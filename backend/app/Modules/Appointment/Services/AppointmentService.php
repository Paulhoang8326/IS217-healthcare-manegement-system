<?php

namespace App\Modules\Appointment\Services;

use App\Enums\AppointmentStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Models\Account;
use App\Models\Appointment;
use App\Models\ConsultationFee;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Models\PaymentRecord;
use App\Models\PaymentTransaction;
use App\Modules\Appointment\Repositories\AccountRepository;
use App\Modules\Appointment\Repositories\AppointmentRepository;
use App\Modules\Appointment\Repositories\ConsultationFeeRepository;
use App\Modules\Appointment\Repositories\DoctorScheduleRepository;
use App\Modules\Appointment\Repositories\PatientRepository;
use App\Modules\Appointment\Repositories\PaymentRecordRepository;
use App\Modules\Appointment\Repositories\PaymentTransactionRepository;
use App\Modules\Appointment\Resources\AppointmentResponse;
use App\Shared\Auth\CurrentUser;
use App\Shared\Exceptions\BusinessException;
use App\Shared\Exceptions\ResourceNotFoundException;
use App\Shared\Support\Sepay;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AppointmentService
{
    private const ACTIVE_STATUSES = ['PENDING', 'CONFIRMED', 'CHECKED_IN', 'IN_PROGRESS'];

    public function __construct(
        private AppointmentRepository $appointmentRepository,
        private AccountRepository $accountRepository,
        private ConsultationFeeRepository $consultationFeeRepository,
        private PatientRepository $patientRepository,
        private DoctorScheduleRepository $doctorScheduleRepository,
        private PaymentRecordRepository $paymentRecordRepository,
        private PaymentTransactionRepository $paymentTransactionRepository,
    ) {}

    public function create(array $request): array
    {
        return DB::transaction(function () use ($request) {
            [$patient, $schedule, $fee] = $this->bookingInputs($request);
            $queue = $this->reserveDoctorScheduleSlot($schedule);
            $appointment = $this->populateAppointmentForBooking($request, $patient, $schedule, $fee);
            $appointment->queue_num = $queue;
            $appointment->status = AppointmentStatus::PENDING->value;
            $appointment->payment_expires_at = now()->addMinutes(10);
            $this->appointmentRepository->saveAndFlush($appointment);
            $this->initializePaymentRecord($appointment);

            return AppointmentResponse::toResponse($appointment->refresh());
        });
    }

    public function createWalkInPaidAppointment(array $request): array
    {
        return DB::transaction(function () use ($request) {
            $account = $this->findCurrentAccountOrThrow();
            [$patient, $schedule, $fee] = $this->bookingInputs($request);
            $this->validateExactAmount($request['receivedAmount'], $fee->price);
            $queue = $this->reserveDoctorScheduleSlot($schedule);
            $appointment = $this->populateAppointmentForBooking($request, $patient, $schedule, $fee);
            $appointment->queue_num = $queue;
            $appointment->status = AppointmentStatus::CHECKED_IN->value;
            $appointment->paid_at = now();
            $appointment->checked_in_at = $appointment->getAttribute('paid_at');
            $this->appointmentRepository->saveAndFlush($appointment);
            $payment = $this->initializePaymentRecord($appointment);
            $payment->received_amount = $request['receivedAmount'];
            $payment->paid_at = $appointment->getAttribute('paid_at');
            $payment->payment_status = PaymentStatus::PAID->value;
            $transaction = $this->buildPaymentTransaction($payment, null, 'CASH', $request['receiptNumber'] ?? null,
                $request['receivedAmount'], now(), null, 'cash', $request['note'] ?? null,
                $request['note'] ?? null, $request['note'] ?? null, $account, $request['receiptNumber'] ?? null);
            $this->paymentTransactionRepository->save($transaction);
            $this->paymentRecordRepository->save($payment);

            return AppointmentResponse::toResponse($appointment->refresh());
        });
    }

    public function getById(int $id): array
    {
        return AppointmentResponse::toResponse($this->findAppointmentOrThrow($id));
    }

    public function getAll(?int $patientId, ?int $doctorId, ?int $doctorScheduleId, ?string $status): array
    {
        return $this->appointmentRepository->findAllByFilters($patientId, $doctorId, $doctorScheduleId, $status)
            ->sortByDesc('created_at')->values()->map(fn ($appointment) => AppointmentResponse::toResponse($appointment))->all();
    }

    public function checkIn(int $id): array
    {
        return DB::transaction(function () use ($id) {
            $appointment = $this->findAppointmentForUpdateOrThrow($id);
            if ($appointment->getAttribute('status') !== AppointmentStatus::CONFIRMED) {
                throw new BusinessException('Only confirmed appointments can be checked in');
            }
            $appointment->status = AppointmentStatus::CHECKED_IN->value;
            $appointment->checked_in_at = now();

            return AppointmentResponse::toResponse($this->appointmentRepository->save($appointment));
        });
    }

    public function start(int $id): array
    {
        return DB::transaction(function () use ($id) {
            $appointment = $this->findAppointmentForUpdateOrThrow($id);
            if ($appointment->getAttribute('status') !== AppointmentStatus::CHECKED_IN) {
                throw new BusinessException('Only checked-in appointments can be started');
            }
            $appointment->status = AppointmentStatus::IN_PROGRESS->value;

            return AppointmentResponse::toResponse($this->appointmentRepository->save($appointment));
        });
    }

    public function cancel(int $id): array
    {
        return DB::transaction(function () use ($id) {
            $appointment = $this->findAppointmentForUpdateOrThrow($id);
            $status = $appointment->getAttribute('status');
            if ($status === AppointmentStatus::CANCELLED) {
                throw new BusinessException('Appointment is already cancelled');
            }
            if (in_array($status, [AppointmentStatus::CONFIRMED, AppointmentStatus::CHECKED_IN, AppointmentStatus::IN_PROGRESS, AppointmentStatus::COMPLETED], true)) {
                throw new BusinessException('Paid appointments cannot be cancelled');
            }
            if ($status !== AppointmentStatus::PENDING) {
                throw new BusinessException('Only pending appointments can be cancelled');
            }
            $this->releaseReservedDoctorScheduleSlot($appointment);
            $appointment->status = AppointmentStatus::CANCELLED->value;
            $appointment->cancelled_at = now();

            return AppointmentResponse::toResponse($this->appointmentRepository->save($appointment));
        });
    }

    public function confirmPaymentFromSepayWebhook(?array $request, ?string $secretKeyHeader): ?array
    {
        return DB::transaction(function () use ($request, $secretKeyHeader) {
            Sepay::validateSepaySecret($secretKeyHeader);
            Sepay::validateIncomingTransfer($request);
            if ($request['id'] !== null) {
                $existing = $this->paymentTransactionRepository->findBySepayTransactionId((string) $request['id']);
                // QUIRK-NEW: A known transaction ID returns its existing appointment before comparing code or amount.
                if ($existing !== null) {
                    return AppointmentResponse::toResponse($existing->paymentRecord->appointment);
                }
            }
            $code = $this->extractAppointmentCode($request);
            if (str_starts_with($code, 'DK')) {
                $id = $this->parseAppointmentId(substr($code, 2));
                $appointment = $this->appointmentRepository->findByIdForUpdate($id)
                    ?? throw new ResourceNotFoundException('Appointment not found with ID: '.$id);
            } else {
                $appointment = $this->appointmentRepository->findByAppointmentCodeForUpdate($code)
                    ?? throw new ResourceNotFoundException('Appointment not found with code: '.$code);
            }
            if ($request['id'] !== null && $appointment->sepay_transaction_id === $request['id']) {
                return AppointmentResponse::toResponse($appointment);
            }

            return $this->confirmPaymentInternal($appointment, $request);
        });
    }

    public function confirmManualPayment(int $id, array $request): array
    {
        return DB::transaction(function () use ($id, $request) {
            $account = $this->findCurrentAccountOrThrow();
            $appointment = $this->findAppointmentForUpdateOrThrow($id);
            $this->validatePendingAppointmentForPayment($appointment);
            $schedule = $this->findDoctorScheduleForUpdateOrThrow($appointment->doctor_schedule_id);
            $payment = $this->findPaymentRecordForUpdateOrThrow($id);
            $appointment->refresh();
            $this->validatePendingAppointmentForPayment($appointment);
            $this->validateScheduleNotExpired($schedule);
            // QUIRK-NEW: Manual payment does not reject an expired reservation, unlike the SePay path.
            $appointment->status = AppointmentStatus::CONFIRMED->value;
            $appointment->paid_at = now();
            $appointment->cancelled_at = null;
            $appointment->payment_reference_code = $request['receiptNumber'];
            $appointment->payment_content = 'Manual payment confirmed at counter';
            $payment->received_amount = $payment->total_price;
            $payment->paid_at = $appointment->getAttribute('paid_at');
            $payment->payment_status = PaymentStatus::PAID->value;
            $transaction = $this->buildPaymentTransaction($payment, null, 'CASH', $request['receiptNumber'], $payment->getAttribute('total_price'),
                now(), null, 'in', 'manual', 'Manual payment confirmed by receptionist: '.($request['note'] ?? ''), '{}', $account, $request['receiptNumber']);
            try {
                $this->paymentTransactionRepository->save($transaction);
                $this->paymentRecordRepository->save($payment);
                $this->appointmentRepository->saveAndFlush($appointment);

                return AppointmentResponse::toResponse($appointment->refresh());
            } catch (QueryException $e) {
                if ($this->isConstraintViolation($e)) {
                    throw new BusinessException('Payment confirmation conflict detected');
                }
                throw $e;
            }
        });
    }

    public function expirePendingPaymentReservations(): void
    {
        DB::transaction(function () {
            $appointments = $this->appointmentRepository->findExpiredPaymentReservationsForUpdate(AppointmentStatus::PENDING, now());
            foreach ($appointments as $appointment) {
                $this->releaseReservedDoctorScheduleSlot($appointment);
                $appointment->status = AppointmentStatus::PAYMENT_EXPIRED->value;
                $appointment->cancelled_at = now();
                $this->appointmentRepository->save($appointment);
            }
        });
    }

    private function bookingInputs(array $request): array
    {
        $patient = $this->patientRepository->findByIdForUpdate($request['patientId']);
        if ($patient === null || $patient->is_active !== 1) {
            throw new ResourceNotFoundException('Patient not found with id: '.$request['patientId']);
        }
        $schedule = $this->findDoctorScheduleForUpdateOrThrow($request['doctorScheduleId']);
        $fee = $this->findConsultationFeeOrThrow($schedule, $request['feeId'] ?? null);
        $this->validateScheduleNotExpired($schedule);
        if ($this->appointmentRepository->findFirstByPatient_PatientIdAndStatusIn($patient->patient_id, self::ACTIVE_STATUSES) !== null) {
            throw new BusinessException('Patient already has an active appointment');
        }
        if (! $fee->isActive()) {
            throw new BusinessException('Consultation fee is inactive');
        }
        if ($schedule->current_booking_count >= $schedule->max_capacity) {
            throw new BusinessException('Doctor schedule is full');
        }

        return [$patient, $schedule, $fee];
    }

    private function findAppointmentOrThrow(int $id): Appointment
    {
        return $this->appointmentRepository->findById($id) ?? throw new ResourceNotFoundException('Appointment not found with id: '.$id);
    }

    private function findAppointmentForUpdateOrThrow(int $id): Appointment
    {
        return $this->appointmentRepository->findByIdForUpdate($id) ?? throw new ResourceNotFoundException('Appointment not found with id: '.$id);
    }

    private function findDoctorScheduleForUpdateOrThrow(int $id): DoctorSchedule
    {
        return $this->doctorScheduleRepository->findByIdForUpdate($id) ?? throw new ResourceNotFoundException('Doctor schedule not found with id: '.$id);
    }

    private function findPaymentRecordForUpdateOrThrow(int $id): PaymentRecord
    {
        return $this->paymentRecordRepository->findByAppointmentIdForUpdate($id)
            ?? throw new ResourceNotFoundException('Appointment payment record not found for appointment id: '.$id);
    }

    private function findCurrentAccountOrThrow(): Account
    {
        $principal = CurrentUser::fromRequest();
        if ($principal === null) {
            throw new BusinessException('Authenticated receptionist account is required');
        }

        return $this->accountRepository->findById($principal->accountId)
            ?? throw new ResourceNotFoundException('Account not found with id: '.$principal->accountId);
    }

    private function findConsultationFeeOrThrow(DoctorSchedule $schedule, ?int $id): ConsultationFee
    {
        if ($id !== null) {
            // QUIRK-NEW: An explicit fee ID is not checked against the doctor's specialty.
            return $this->consultationFeeRepository->findById($id) ?? throw new ResourceNotFoundException('Consultation fee not found with id: '.$id);
        }
        if ($schedule->doctor?->specialty === null) {
            throw new BusinessException('Doctor specialty is required to resolve consultation fee');
        }
        $specialtyId = $schedule->doctor->specialty->specialty_id;

        return $this->consultationFeeRepository->findFirstBySpecialtyRef_SpecialtyIdAndIsActive($specialtyId, 1)
            ?? throw new ResourceNotFoundException('Consultation fee not found for doctor specialty id: '.$specialtyId);
    }

    private function validatePendingAppointmentForPayment(Appointment $appointment): void
    {
        if ($appointment->getAttribute('status') !== AppointmentStatus::PENDING) {
            throw new BusinessException($appointment->getAttribute('status') === AppointmentStatus::CONFIRMED
                ? 'Appointment is already confirmed' : 'Only pending appointments can be confirmed for payment');
        }
    }

    private function validateScheduleNotExpired(DoctorSchedule $schedule): void
    {
        if ($schedule->getAttribute('schedule_date') !== null && $schedule->getAttribute('schedule_date')->toDateString() < today()->toDateString()) {
            throw new BusinessException('Doctor schedule has expired');
        }
    }

    private function reserveDoctorScheduleSlot(DoctorSchedule $schedule): int
    {
        $schedule->last_queue_number++;
        $schedule->current_booking_count++;
        $this->doctorScheduleRepository->save($schedule);

        return $schedule->last_queue_number;
    }

    private function releaseReservedDoctorScheduleSlot(Appointment $appointment): void
    {
        if ($appointment->queue_num === null) {
            return;
        }
        $schedule = $this->findDoctorScheduleForUpdateOrThrow($appointment->doctor_schedule_id);
        if ($schedule->current_booking_count > 0) {
            $schedule->current_booking_count--;
            $this->doctorScheduleRepository->save($schedule);
        }
    }

    private function populateAppointmentForBooking(array $request, Patient $patient, DoctorSchedule $schedule, ConsultationFee $fee): Appointment
    {
        return new Appointment([
            'patient_id' => $patient->patient_id, 'doctor_schedule_id' => $schedule->doctor_schedule_id, 'fee_id' => $fee->fee_id,
            'initial_symptoms' => $request['initialSymptoms'], 'visit_reason' => $request['visitReason'],
            'fee_name_snapshot' => $fee->fee_name, 'fee_price_snapshot' => $fee->price, 'appointment_code' => $this->generateUniqueAppointmentCode(),
        ]);
    }

    private function initializePaymentRecord(Appointment $appointment): PaymentRecord
    {
        return $this->paymentRecordRepository->save(new PaymentRecord([
            'appointment_id' => $appointment->appointment_id, 'request_code' => $appointment->appointment_code,
            'total_price' => $appointment->fee_price_snapshot, 'received_amount' => '0', 'payment_status' => PaymentStatus::UNPAID,
        ]));
    }

    private function confirmPaymentInternal(Appointment $appointment, array $request): array
    {
        $this->validatePendingAppointmentForPayment($appointment);
        $schedule = $this->findDoctorScheduleForUpdateOrThrow($appointment->doctor_schedule_id);
        $payment = $this->findPaymentRecordForUpdateOrThrow($appointment->appointment_id);
        $appointment->refresh();
        if ($request['id'] !== null && $appointment->sepay_transaction_id === $request['id']) {
            return AppointmentResponse::toResponse($appointment);
        }
        $this->validatePendingAppointmentForPayment($appointment);
        if ($appointment->getAttribute('payment_expires_at') !== null && $appointment->getAttribute('payment_expires_at')->lessThanOrEqualTo(now())) {
            throw new BusinessException('Appointment payment reservation has expired');
        }
        if ($request['transferAmount'] === null) {
            throw new BusinessException('Transfer amount is required');
        }
        if (BigDecimal::of($payment->getAttribute('total_price'))->compareTo($request['transferAmount']) !== 0) {
            throw new BusinessException('Transfer amount does not match expected appointment amount: '.$payment->total_price);
        }
        $this->validateScheduleNotExpired($schedule);
        $appointment->status = AppointmentStatus::CONFIRMED->value;
        $appointment->paid_at = now();
        $appointment->cancelled_at = null;
        $appointment->sepay_transaction_id = $request['id'];
        $appointment->payment_reference_code = $request['referenceCode'];
        $appointment->payment_content = $request['content'];
        $payment->setAttribute('received_amount', (string) $request['transferAmount']);
        $payment->paid_at = $appointment->getAttribute('paid_at');
        $payment->payment_status = (BigDecimal::of($payment->getAttribute('received_amount'))->compareTo($payment->getAttribute('total_price')) < 0 ? PaymentStatus::PARTIAL : PaymentStatus::PAID)->value;
        $gateway = Sepay::isBlank($request['gateway']) ? 'SEPAY' : $request['gateway'];
        $transaction = $this->buildPaymentTransaction($payment, $request['id'], $gateway, $request['referenceCode'],
            (string) $request['transferAmount'], $this->parseTransactionDate($request['transactionDate']), $request['accountNumber'],
            $request['transferType'], $request['content'], $request['description'], $this->toRawJson($request), null, null);
        try {
            $this->paymentTransactionRepository->save($transaction);
            $this->paymentRecordRepository->save($payment);
            $this->appointmentRepository->saveAndFlush($appointment);

            return AppointmentResponse::toResponse($appointment->refresh());
        } catch (QueryException $e) {
            if ($this->isConstraintViolation($e)) {
                throw new BusinessException('Payment confirmation conflict detected');
            }
            throw $e;
        }
    }

    private function validateExactAmount(?string $received, string $expected): void
    {
        if ($received === null) {
            throw new BusinessException('Received amount is required');
        }
        if (BigDecimal::of($received)->compareTo($expected) !== 0) {
            throw new BusinessException('Received amount must match consultation fee amount');
        }
    }

    private function generateUniqueAppointmentCode(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = 'APT-'.strtoupper(substr(str_replace('-', '', (string) Str::uuid()), 0, 8));
            if (! $this->appointmentRepository->existsByAppointmentCode($code)) {
                return $code;
            }
        }
        throw new BusinessException('Unable to generate unique appointment code');
    }

    private function extractAppointmentCode(array $request): string
    {
        foreach (['content', 'description', 'code'] as $field) {
            if (! Sepay::isBlank($request[$field])) {
                $text = mb_strtoupper($request[$field]);
                foreach (['/APT-[A-Z0-9]{8}/', '/DK[0-9]+/'] as $pattern) {
                    if (preg_match($pattern, $text, $match)) {
                        return $match[0];
                    }
                }
                if ($field === 'code') {
                    return Sepay::trim($text);
                }
            }
        }
        throw new BusinessException('Appointment code was not found in SePay webhook payload');
    }

    private function parseAppointmentId(string $value): int
    {
        $digits = ltrim($value, '0');
        if (! preg_match('/^[0-9]+$/D', $value) || strlen($digits) > 19 || (strlen($digits) === 19 && strcmp($digits, (string) PHP_INT_MAX) > 0)) {
            throw new \InvalidArgumentException('For input string: "'.$value.'"');
        }

        return (int) $value;
    }

    private function parseTransactionDate(?string $date): ?DateTimeInterface
    {
        if (Sepay::isBlank($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,9})?)?(?:Z|[+-]\d{2}(?::\d{2}(?::\d{2})?)?)$/iD', $date)) {
            return null;
        }
        try {
            $parsed = new DateTimeImmutable($date);
            $errors = DateTimeImmutable::getLastErrors();

            return abs($parsed->getOffset()) > 64800 || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) ? null : $parsed;
        } catch (\Exception) {
            return null;
        }
    }

    private function toRawJson(array $request): string
    {
        $parts = [];
        foreach (['id', 'gateway', 'transactionDate', 'accountNumber', 'code', 'content', 'transferType', 'transferAmount', 'accumulated', 'subAccount', 'referenceCode', 'description'] as $field) {
            $value = $request[$field];
            $quoted = $value === null ? 'null' : (in_array($field, ['id', 'transferAmount', 'accumulated'], true)
                ? (string) $value : '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"');
            // QUIRK-NEW: Java escapes quotes and backslashes only; control characters remain literal.
            $parts[] = '"'.$field.'":'.$quoted;
        }

        return '{'.implode(',', $parts).'}';
    }

    private function buildPaymentTransaction(PaymentRecord $payment, ?int $transactionId, ?string $gateway, ?string $reference,
        string $amount, ?DateTimeInterface $date, ?string $accountNumber, ?string $transferType, ?string $content,
        ?string $description, ?string $raw, ?Account $account, ?string $receipt): PaymentTransaction
    {
        return new PaymentTransaction([
            'payment_record_id' => $payment->payment_record_id, 'sepay_transaction_id' => $transactionId === null ? null : (string) $transactionId,
            'gateway' => Sepay::isBlank($gateway) ? 'UNKNOWN' : $gateway, 'reference_code' => $reference, 'transfer_amount' => $amount,
            'transaction_date' => $date ?? now(), 'account_number' => $accountNumber, 'transfer_type' => $transferType,
            'description' => $description, 'content' => $content, 'raw_data' => $raw, 'confirmed_by_account_id' => $account?->account_id,
            'receipt_number' => $receipt, 'process_status' => PaymentTransactionStatus::SUCCESS,
        ]);
    }

    private function isConstraintViolation(QueryException $e): bool
    {
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());

        return in_array(substr($state, 0, 2), ['22', '23'], true)
            || in_array((int) ($e->errorInfo[1] ?? 0), [1265, 1364, 1366, 3819], true);
    }
}
