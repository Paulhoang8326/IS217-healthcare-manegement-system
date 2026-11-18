<?php

namespace App\Modules\Appointment\Tests;

use App\Models\Account;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\ConsultationFee;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Models\PaymentRecord;
use App\Models\PaymentTransaction;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Specialty;
use App\Shared\Auth\JwtServiceImpl;
use App\Shared\Support\OptimisticLock;
use CurlHandle;
use CurlMultiHandle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final class AppointmentHttpConcurrency
{
    private array $owned = [];

    private array $scheduleIds = [];

    private int $day = 0;

    private int $checks = 0;

    private int $barriers = 0;

    private string $runId;

    private string $token;

    private Doctor $doctor;

    private Room $room;

    private ConsultationFee $fee;

    public function __construct(
        private readonly string $baseUrl,
        private readonly int $iterations,
        private readonly ?string $secondaryBaseUrl = null,
    ) {}

    public function run(): array
    {
        $this->guardEnvironment();
        try {
            $this->fixtures();
            $probe = $this->schedule();
            foreach ($this->origins() as $origin) {
                $response = $this->request('GET', '/api/doctor-schedules/'.$probe->getKey(), null, $origin);
                $this->check($response['status'] === 200 && ($this->json($response)['note'] ?? null) === 'http-concurrency:'.$this->runId,
                    'HTTP server does not read this run\'s healthcare_test fixture; no mutating HTTP request was sent');
            }
            $results = [];
            for ($iteration = 1; $iteration <= $this->iterations; $iteration++) {
                $this->lastSlot();
                $this->samePatient();
                $this->duplicateWebhook();
                $this->expiredWebhookAndScheduler();
                $results[] = ['iteration' => $iteration, 'scenarios' => 4, 'passed' => 4];
            }

            return ['success' => true, 'iterations' => $this->iterations, 'scenarios' => 4 * $this->iterations,
                'checks' => $this->checks, 'lockBarriers' => $this->barriers, 'results' => $results];
        } finally {
            $this->cleanup();
        }
    }

    private function guardEnvironment(): void
    {
        foreach ($this->origins() as $origin) {
            $url = parse_url($origin);
            if ($url === false || ! in_array($url['scheme'] ?? '', ['http', 'https'], true)
                || ! in_array($url['host'] ?? '', ['127.0.0.1', 'localhost', '[::1]'], true)
                || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
                || ! in_array($url['path'] ?? '', ['', '/'], true)) {
                throw new RuntimeException('Use a loopback HTTP server origin without credentials, path, query or fragment');
            }
        }
        if ($this->iterations < 1 || $this->iterations > 20) {
            throw new RuntimeException('Iterations must be between 1 and 20');
        }
        if (DB::connection()->getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'healthcare_test') {
            throw new RuntimeException('HTTP concurrency requires the dedicated healthcare_test MySQL database');
        }
        if (DB::connection()->transactionLevel() !== 0 || Appointment::exists()) {
            throw new RuntimeException('Use healthcare_test without existing appointments or an enclosing transaction; the scheduler scans all expired appointments');
        }
        if (trim((string) config('jwt.secret')) === '' || trim((string) config('sepay.webhook-secret')) === '') {
            throw new RuntimeException('Configure JWT_SECRET and SEPAY_WEBHOOK_SECRET identically for the CLI and HTTP server');
        }
        if (! extension_loaded('curl')) {
            throw new RuntimeException('HTTP concurrency requires the curl extension');
        }
    }

    private function fixtures(): void
    {
        $this->runId = bin2hex(random_bytes(6));
        $admin = $this->account('ADMIN');
        config(['jwt.access-token-expiration-ms' => 600000]);
        $this->token = app(JwtServiceImpl::class)->generateToken($admin->getKey(), $admin->email, 'ADMIN');
        $specialty = $this->own(Specialty::create(['specialty_code' => 'HC'.$this->runId, 'specialty_name' => 'HTTP concurrency '.$this->runId]));
        $this->doctor = $this->own(Doctor::create(['account_id' => $this->account('DOCTOR')->getKey(),
            'full_name' => 'HTTP doctor '.$this->runId, 'specialty_id' => $specialty->getKey(), 'license_num' => 'HC'.$this->runId]));
        $branch = $this->own(Branch::create(['branch_name' => 'HTTP concurrency', 'branch_address' => 'Local test fixture']));
        $type = $this->own(RoomType::create(['room_type_name' => 'HTTP concurrency '.$this->runId]));
        $this->room = $this->own(Room::create(['room_code' => 'HC'.$this->runId, 'room_type_id' => $type->getKey(), 'branch_id' => $branch->getKey()]));
        $this->fee = $this->own(ConsultationFee::create(['fee_code' => 'HC'.$this->runId, 'fee_name' => 'HTTP consultation',
            'specialty' => 'HTTP concurrency', 'specialty_id' => $specialty->getKey(), 'price' => '150000.00']));
    }

    private function account(string $name): Account
    {
        $role = Role::firstOrCreate(['role_name' => $name]);
        if ($role->wasRecentlyCreated) {
            $this->own($role);
        }

        return $this->own(Account::create(['email' => $this->runId.'.'.strtolower($name).'@example.invalid', 'role_id' => $role->getKey()]));
    }

    private function schedule(): DoctorSchedule
    {
        $schedule = $this->own(DoctorSchedule::create(['doctor_id' => $this->doctor->getKey(), 'room_id' => $this->room->getKey(),
            'schedule_date' => today()->addDays(++$this->day)->toDateString(), 'shift' => 'MORNING', 'max_capacity' => 1,
            'note' => 'http-concurrency:'.$this->runId]));
        $this->scheduleIds[] = $schedule->getKey();

        return $schedule;
    }

    private function booking(DoctorSchedule $schedule, ?Patient $patient = null): array
    {
        $patient ??= $this->own(Patient::create(['full_name' => 'HTTP patient '.$this->runId]));

        return ['patientId' => $patient->getKey(), 'doctorScheduleId' => $schedule->getKey(), 'feeId' => $this->fee->getKey(),
            'initialSymptoms' => 'HTTP concurrency fixture', 'visitReason' => 'Local concurrency check'];
    }

    private function lastSlot(): void
    {
        $schedule = $this->schedule();
        $requests = [$this->spec('/api/appointments', $this->booking($schedule)), $this->spec('/api/appointments', $this->booking($schedule))];
        $responses = $this->race($requests, 'doctor_schedule', fn () => DoctorSchedule::whereKey($schedule->getKey())->lockForUpdate()->firstOrFail());
        $this->checkBookingResponses($responses, 'Doctor schedule is full');
        $this->check(Appointment::where('doctor_schedule_id', $schedule->getKey())->count() === 1, 'Last slot created more than one appointment');
        $this->check($schedule->fresh()->current_booking_count === 1 && $schedule->fresh()->last_queue_number === 1, 'Last slot counters drifted');
    }

    private function samePatient(): void
    {
        $patient = $this->own(Patient::create(['full_name' => 'Shared HTTP patient '.$this->runId]));
        $schedules = [$this->schedule(), $this->schedule()];
        $requests = array_map(fn ($schedule) => $this->spec('/api/appointments', $this->booking($schedule, $patient)), $schedules);
        $responses = $this->race($requests, 'patient', fn () => Patient::whereKey($patient->getKey())->lockForUpdate()->firstOrFail());
        $this->checkBookingResponses($responses, 'Patient already has an active appointment');
        $this->check(Appointment::where('patient_id', $patient->getKey())->count() === 1, 'Patient acquired two active appointments');
        $counts = array_map(fn ($schedule) => $schedule->fresh()->current_booking_count, $schedules);
        $queues = array_map(fn ($schedule) => $schedule->fresh()->last_queue_number, $schedules);
        $this->check(array_sum($counts) === 1 && array_sum($queues) === 1, 'Failed patient booking consumed a slot or queue number');
    }

    private function duplicateWebhook(): void
    {
        $schedule = $this->schedule();
        $appointment = $this->createBooking($schedule);
        $payload = $this->webhook($appointment);
        $spec = $this->spec('/api/appointments/sepay/webhook', $payload, true);
        $responses = $this->race([$spec, $spec], 'appointment', fn () => Appointment::whereKey($appointment['appointmentId'])->lockForUpdate()->firstOrFail());
        foreach ($responses as $response) {
            $this->check($response['status'] === 200 && $this->json($response) === ['success' => true,
                'appointmentId' => $appointment['appointmentId'], 'appointmentCode' => $appointment['appointmentCode']], 'Duplicate webhook response drifted');
        }
        $stored = Appointment::findOrFail($appointment['appointmentId']);
        $payment = $stored->paymentRecord;
        $this->check($stored->getAttribute('status')->value === 'CONFIRMED' && $stored->sepay_transaction_id === $payload['id'], 'Webhook did not confirm the appointment once');
        $this->check($stored->version_number === 1 && $stored->getAttribute('paid_at') !== null && $stored->getAttribute('cancelled_at') === null, 'Webhook rewrote version or timestamps');
        $this->check($payment->getAttribute('payment_status')->value === 'PAID' && $payment->getAttribute('received_amount') === '150000.00', 'Webhook payment amount drifted');
        $this->check(PaymentTransaction::where('payment_record_id', $payment->getKey())->count() === 1, 'Duplicate webhook inserted multiple transactions');
        $this->check($schedule->fresh()->current_booking_count === 1 && $schedule->fresh()->version_number === 1, 'Payment changed the reserved slot');
    }

    private function expiredWebhookAndScheduler(): void
    {
        $schedule = $this->schedule();
        $appointment = $this->createBooking($schedule);
        OptimisticLock::update('appointment', 'appointment_id', $appointment['appointmentId'], 0,
            ['payment_expires_at' => now()->subSecond()->toDateTimeString(), 'updated_at' => now()->toDateTimeString()]);
        $scheduler = new Process([PHP_BINARY, 'artisan', 'appointments:expire-payment-reservations'], base_path(), timeout: 20);
        $response = $this->race([$this->spec('/api/appointments/sepay/webhook', $this->webhook($appointment), true)], 'appointment',
            fn () => Appointment::whereKey($appointment['appointmentId'])->lockForUpdate()->firstOrFail(), $scheduler)[0];
        $this->check($response['status'] === 400 && in_array($response['body'], ['Appointment payment reservation has expired',
            'Only pending appointments can be confirmed for payment'], true), 'Expired webhook did not preserve the Java error');
        $stored = Appointment::findOrFail($appointment['appointmentId']);
        $payment = $stored->paymentRecord;
        $this->check($stored->getAttribute('status')->value === 'PAYMENT_EXPIRED' && $stored->version_number === 2, 'Scheduler overwrote payment or expired twice');
        $this->check($stored->getAttribute('paid_at') === null && $stored->getAttribute('cancelled_at') !== null && $stored->sepay_transaction_id === null, 'Failed payment left confirmation data');
        $this->check($payment->getAttribute('payment_status')->value === 'UNPAID' && $payment->getAttribute('received_amount') === '0.00', 'Failed payment changed money');
        $this->check(PaymentTransaction::where('payment_record_id', $payment->getKey())->count() === 0, 'Failed payment inserted a transaction');
        $this->check($schedule->fresh()->current_booking_count === 0 && $schedule->fresh()->last_queue_number === 1
            && $schedule->fresh()->version_number === 2, 'Scheduler released the slot incorrectly');
    }

    private function createBooking(DoctorSchedule $schedule): array
    {
        $response = $this->request('POST', '/api/appointments', $this->booking($schedule));
        $this->check($response['status'] === 201, 'Could not prepare HTTP booking: '.$response['body']);

        return $this->json($response);
    }

    private function webhook(array $appointment): array
    {
        return ['id' => random_int(1, PHP_INT_MAX), 'transferType' => 'in', 'transferAmount' => 150000,
            'accountNumber' => config('sepay.account-number'), 'content' => 'Payment '.$appointment['appointmentCode']];
    }

    private function checkBookingResponses(array $responses, string $error): void
    {
        usort($responses, fn ($a, $b) => $a['status'] <=> $b['status']);
        $this->check(array_column($responses, 'status') === [201, 400], 'Concurrent bookings did not produce one success and one rejection');
        $this->check($responses[1]['body'] === $error, 'Concurrent booking error differs from Java');
    }

    private function race(array $specs, string $table, callable $lock, ?Process $scheduler = null): array
    {
        $multi = curl_multi_init();
        $handles = [];
        DB::beginTransaction();
        try {
            $lock();
            $origins = $this->origins();
            foreach ($specs as $index => $spec) {
                $handle = $this->handle($spec, $origins[$index % count($origins)]);
                $handles[] = $handle;
                curl_multi_add_handle($multi, $handle);
            }
            $scheduler?->start();
            $deadline = microtime(true) + 8;
            do {
                $this->tick($multi, $active);
                $waiting = 0;
                foreach (DB::select('SHOW FULL PROCESSLIST') as $process) {
                    $row = (array) $process;
                    $sql = strtolower((string) ($row['Info'] ?? ''));
                    if (($row['db'] ?? null) === 'healthcare_test' && str_contains($sql, '`'.$table.'`') && str_contains($sql, 'for update')) {
                        $waiting++;
                    }
                }
                if ($waiting >= count($handles) + ($scheduler === null ? 0 : 1)) {
                    break;
                }
                if (microtime(true) >= $deadline || $active === 0) {
                    $statuses = array_map(fn ($handle) => curl_getinfo($handle, CURLINFO_HTTP_CODE), $handles);
                    throw new RuntimeException('Requests did not reach concurrent SQL locks on '.$table.' ('.$waiting.' waiting; HTTP '.implode(',', $statuses)
                        .'); use at least two HTTP workers and the same MySQL user as the CLI');
                }
                curl_multi_select($multi, 0.01);
            } while (true);
            $this->barriers++;
            DB::commit();
            $this->drain($multi);
            if ($scheduler !== null) {
                $scheduler->wait();
                $this->check($scheduler->getExitCode() === 0, 'Scheduler command failed: '.$scheduler->getErrorOutput().$scheduler->getOutput());
            }

            return array_map(fn ($handle) => $this->response($handle), $handles);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            try {
                $this->drain($multi);
            } catch (Throwable) {
                // Preserve the original failure while releasing the remaining HTTP clients.
            }
            foreach ($handles as $handle) {
                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
            }
            curl_multi_close($multi);
            $scheduler?->stop(0);
        }
    }

    private function tick(CurlMultiHandle $multi, ?int &$active): void
    {
        if (curl_multi_exec($multi, $active) !== CURLM_OK) {
            throw new RuntimeException('HTTP multi stack failed');
        }
    }

    private function drain(CurlMultiHandle $multi): void
    {
        do {
            $this->tick($multi, $active);
            if ($active > 0) {
                curl_multi_select($multi, 0.05);
            }
        } while ($active > 0);
    }

    private function spec(string $path, array $body, bool $webhook = false): array
    {
        return ['method' => 'POST', 'path' => $path, 'body' => $body, 'webhook' => $webhook];
    }

    private function origins(): array
    {
        return $this->secondaryBaseUrl === null ? [$this->baseUrl] : [$this->baseUrl, $this->secondaryBaseUrl];
    }

    private function request(string $method, string $path, ?array $body = null, ?string $origin = null): array
    {
        $handle = $this->handle(['method' => $method, 'path' => $path, 'body' => $body, 'webhook' => false], $origin);
        try {
            $content = curl_exec($handle);

            return $this->response($handle, is_string($content) ? $content : '');
        } finally {
            curl_close($handle);
        }
    }

    private function handle(array $spec, ?string $origin = null): CurlHandle
    {
        $handle = curl_init(rtrim($origin ?? $this->baseUrl, '/').$spec['path']);
        if ($handle === false) {
            throw new RuntimeException('Could not initialize HTTP client');
        }
        curl_setopt_array($handle, [CURLOPT_CUSTOMREQUEST => $spec['method'], CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json',
                $spec['webhook'] ? 'X-Secret-Key: '.config('sepay.webhook-secret') : 'Authorization: Bearer '.$this->token]]);
        if ($spec['body'] !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($spec['body'], JSON_THROW_ON_ERROR));
        }

        return $handle;
    }

    private function response(CurlHandle $handle, ?string $content = null): array
    {
        if (curl_errno($handle) !== CURLE_OK) {
            throw new RuntimeException('HTTP transport failed: '.curl_error($handle));
        }

        return ['status' => curl_getinfo($handle, CURLINFO_HTTP_CODE), 'body' => $content ?? (string) curl_multi_getcontent($handle)];
    }

    private function json(array $response): array
    {
        return json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
        $this->checks++;
    }

    /** @template T of Model
     * @param  T  $model
     * @return T
     */
    private function own(Model $model): Model
    {
        $this->owned[] = $model;

        return $model;
    }

    private function cleanup(): void
    {
        if ($this->owned === []) {
            return;
        }
        DB::transaction(function (): void {
            $appointments = Appointment::whereIn('doctor_schedule_id', $this->scheduleIds)->pluck('appointment_id');
            $payments = PaymentRecord::whereIn('appointment_id', $appointments)->pluck('payment_record_id');
            PaymentTransaction::whereIn('payment_record_id', $payments)->delete();
            PaymentRecord::whereIn('payment_record_id', $payments)->delete();
            Appointment::whereIn('appointment_id', $appointments)->delete();
            foreach (array_reverse($this->owned) as $model) {
                $model->delete();
            }
        });
    }
}
