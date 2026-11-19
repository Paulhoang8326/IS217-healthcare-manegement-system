<?php

namespace App\Modules\Appointment\Tests;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\PaymentRecord;
use App\Models\PaymentTransaction;
use App\Modules\Appointment\Repositories\PaymentRecordRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schedule;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Shared\PersonOneModuleTestCase;

class AppointmentTest extends PersonOneModuleTestCase
{
    use RefreshDatabase;

    private function createBooking(array $overrides = []): array
    {
        $schedule = $this->schedule();

        return $this->postJson('/api/appointments', $this->booking($schedule, $overrides), $this->auth('PATIENT'))->assertCreated()->json();
    }

    private function webhook(array $appointment, array $overrides = []): array
    {
        return $overrides + ['id' => 101, 'transferType' => 'in', 'transferAmount' => 150000,
            'accountNumber' => '123456', 'content' => 'Payment '.$appointment['appointmentCode']];
    }

    public function test_booking_returns_java_dto_and_reserves_exactly_one_slot(): void
    {
        $this->postJson('/api/appointments', [])->assertUnauthorized();
        $data = $this->createBooking();
        $this->assertCount(30, $data);
        $this->assertSame('PENDING', $data['status']);
        $this->assertSame('UNPAID', $data['paymentStatus']);
        $this->assertSame(150000, $data['feePrice']);
        $this->assertSame(150000, $data['expectedPaymentAmount']);
        $this->assertSame(0, $data['receivedPaymentAmount']);
        $this->assertSame('2026-11-17T09:10:00', $data['paymentExpiresAt']);
        $this->assertSame('07:00 - 11:00', $data['appointmentTime']);
        $this->assertNull($data['paidAt']);
        $this->assertMatchesRegularExpression('/^APT-[A-Z0-9]{8}$/D', $data['appointmentCode']);
        $schedule = Appointment::first()->doctorSchedule;
        $this->assertSame(1, $schedule->current_booking_count);
        $this->assertSame(1, $schedule->last_queue_number);
        $this->assertSame(1, $schedule->version_number);
    }

    public function test_duplicate_active_booking_is_rejected_without_consuming_another_slot(): void
    {
        $data = $this->createBooking();
        $schedule = Appointment::find($data['appointmentId'])->doctorSchedule;
        $this->postJson('/api/appointments', $this->booking($schedule), $this->auth('PATIENT'))
            ->assertStatus(400)->assertContent('Patient already has an active appointment');
        $this->assertSame(1, $schedule->fresh()->current_booking_count);
        $this->assertSame(1, Appointment::count());
    }

    public function test_body_validation_is_not_replaced_with_business_validation(): void
    {
        $schedule = $this->schedule();
        $this->postJson('/api/appointments', $this->booking($schedule, ['patientId' => null]), $this->auth())
            ->assertStatus(400)->assertExactJson(['patientId' => 'Patient id must not be null']);
        $this->postJson('/api/appointments', $this->booking($schedule, ['initialSymptoms' => ' ']), $this->auth())
            ->assertStatus(400)->assertExactJson(['initialSymptoms' => 'Initial symptoms must not be blank']);
        $this->postJson('/api/appointments', $this->booking($schedule, ['doctorScheduleId' => 'bad']), $this->auth())
            ->assertStatus(400)->assertContent('Invalid request body. Please check JSON syntax and date format yyyy-MM-dd');
    }

    public function test_full_inactive_and_expired_inputs_preserve_java_messages(): void
    {
        $schedule = $this->schedule(['current_booking_count' => 5]);
        $body = $this->booking($schedule);
        $this->postJson('/api/appointments', $body, $this->auth())->assertStatus(400)->assertContent('Doctor schedule is full');
        $schedule->current_booking_count = 0;
        $schedule->save();
        $this->fee->is_active = 0;
        $this->fee->save();
        $this->postJson('/api/appointments', $body + ['feeId' => $this->fee->fee_id], $this->auth())
            ->assertStatus(400)->assertContent('Consultation fee is inactive');
        $this->fee->is_active = 1;
        $this->fee->save();
        $this->patient->is_active = 0;
        $this->patient->save();
        $this->postJson('/api/appointments', $body, $this->auth())
            ->assertNotFound()->assertContent('Patient not found with id: '.$this->patient->patient_id);
        $this->patient->is_active = 1;
        $this->patient->save();
        $schedule->schedule_date = '2026-11-16';
        $schedule->save();
        $this->postJson('/api/appointments', $body, $this->auth())->assertStatus(400)->assertContent('Doctor schedule has expired');
        $this->assertSame(0, Appointment::count());
    }

    public function test_cancel_releases_capacity_but_never_reuses_queue_numbers(): void
    {
        $data = $this->createBooking();
        $this->postJson('/api/appointments/'.$data['appointmentId'].'/cancel', [], $this->auth('PATIENT'))
            ->assertOk()->assertJsonPath('status', 'CANCELLED');
        $schedule = Appointment::first()->doctorSchedule;
        $this->assertSame(0, $schedule->current_booking_count);
        $this->assertSame(1, $schedule->last_queue_number);
        $this->postJson('/api/appointments', $this->booking($schedule), $this->auth('PATIENT'))
            ->assertCreated()->assertJsonPath('queueNum', 2);
        $this->postJson('/api/appointments/'.$data['appointmentId'].'/cancel', [], $this->auth())
            ->assertStatus(400)->assertContent('Appointment is already cancelled');
    }

    public function test_walk_in_payment_requires_receptionist_and_exact_amount(): void
    {
        $schedule = $this->schedule();
        $body = $this->booking($schedule, ['receivedAmount' => '150000.000', 'note' => 'cash note']);
        $this->postJson('/api/appointments/walk-in', $body, $this->auth('DOCTOR'))->assertForbidden();
        $this->postJson('/api/appointments/walk-in', array_replace($body, ['receivedAmount' => '149999.99']), $this->auth('RECEPTIONIST'))
            ->assertStatus(400)->assertContent('Received amount must match consultation fee amount');
        $this->postJson('/api/appointments/walk-in', $body, $this->auth('RECEPTIONIST'))
            ->assertCreated()->assertJsonPath('status', 'CHECKED_IN')->assertJsonPath('paymentStatus', 'PAID')
            ->assertJsonPath('paidAt', '2026-11-17T09:00:00')->assertJsonPath('paymentExpiresAt', null);
        $transaction = PaymentTransaction::first();
        $this->assertSame('CASH', $transaction->gateway);
        $this->assertSame('cash', $transaction->transfer_type);
        $this->assertSame('cash note', $transaction->raw_data);
        $this->assertNull($transaction->receipt_number);
        $this->assertSame($this->account('RECEPTIONIST')->account_id, $transaction->confirmed_by_account_id);
    }

    public function test_manual_payment_keeps_the_expired_reservation_quirk(): void
    {
        $data = $this->createBooking();
        Carbon::setTestNow(Carbon::parse('2026-11-17 09:11:00'));
        $url = '/api/appointments/'.$data['appointmentId'].'/confirm-payment-manual';
        $this->postJson($url, ['receiptNumber' => 'R1'], $this->auth('DOCTOR'))->assertForbidden();
        $this->postJson($url, ['receiptNumber' => 'R1', 'note' => 'collected'], $this->auth('RECEPTIONIST'))
            ->assertOk()->assertJsonPath('status', 'CONFIRMED')->assertJsonPath('paymentStatus', 'PAID');
        $transaction = PaymentTransaction::first();
        $this->assertSame('manual', $transaction->content);
        $this->assertSame('Manual payment confirmed by receptionist: collected', $transaction->description);
    }

    public function test_sepay_callback_is_public_and_replay_is_idempotent_before_amount_comparison(): void
    {
        $data = $this->createBooking();
        $body = $this->webhook($data, ['transferType' => 'IN', 'transactionDate' => '2026-11-17T10:30:00+07:00']);
        $headers = ['Authorization' => 'Apikey webhook-test-secret'];
        $this->postJson('/api/appointments/sepay/webhook', $body, $headers)->assertOk()
            ->assertExactJson(['success' => true, 'appointmentId' => $data['appointmentId'], 'appointmentCode' => $data['appointmentCode']]);
        $this->postJson('/api/appointments/sepay/webhook', array_replace($body, ['transferAmount' => 1, 'content' => 'not an appointment code']), $headers)->assertOk();
        $this->assertSame(1, PaymentTransaction::count());
        $transaction = PaymentTransaction::first();
        $this->assertSame('SEPAY', $transaction->gateway);
        $this->assertSame('2026-11-17 10:30:00', $transaction->getAttribute('transaction_date')->format('Y-m-d H:i:s'));
        $this->getJson('/api/appointments/'.$data['appointmentId'], $this->auth())->assertOk()->assertJsonPath('paymentStatus', 'PAID');
    }

    public function test_sepay_uses_business_checks_without_valid_body_constraints(): void
    {
        $data = $this->createBooking();
        $body = $this->webhook($data);
        $this->postJson('/api/appointments/sepay/webhook', $body)->assertStatus(400)->assertContent('Invalid SePay webhook secret');
        $headers = ['X-Secret-Key' => 'webhook-test-secret'];
        $this->postJson('/api/appointments/sepay/webhook', array_replace($body, ['transferType' => 'out']), $headers)
            ->assertStatus(400)->assertContent('Only incoming transfers can confirm appointments');
        $this->postJson('/api/appointments/sepay/webhook', array_replace($body, ['transferAmount' => 100]), $headers)
            ->assertStatus(400)->assertContent('Transfer amount does not match expected appointment amount: 150000.00');
        $this->postJson('/api/appointments/sepay/webhook', array_replace($body, ['id' => 'bad']), $headers)
            ->assertStatus(400)->assertContent('Invalid request body. Please check JSON syntax and date format yyyy-MM-dd');
        $this->assertSame(0, PaymentTransaction::count());
        $this->assertSame('PENDING', Appointment::first()->getAttribute('status')->value);
    }

    public function test_sepay_rejects_exact_expiry_boundary_and_supports_dk_code(): void
    {
        $data = $this->createBooking();
        Carbon::setTestNow(Carbon::parse('2026-11-17 09:10:00'));
        $body = $this->webhook($data, ['content' => 'DK'.$data['appointmentId']]);
        $this->postJson('/api/appointments/sepay/webhook', $body, ['X-Secret-Key' => 'webhook-test-secret'])
            ->assertStatus(400)->assertContent('Appointment payment reservation has expired');
        $this->assertSame(0, PaymentTransaction::count());
    }

    public function test_check_in_and_start_keep_state_transitions_and_reject_paid_cancellation(): void
    {
        $data = $this->createBooking();
        $id = $data['appointmentId'];
        $this->postJson('/api/appointments/'.$id.'/start', [], $this->auth())
            ->assertStatus(400)->assertContent('Only checked-in appointments can be started');
        $this->postJson('/api/appointments/'.$id.'/confirm-payment-manual', ['receiptNumber' => 'R'], $this->auth())->assertOk();
        $this->postJson('/api/appointments/'.$id.'/check-in', [], $this->auth('PATIENT'))->assertOk()->assertJsonPath('status', 'CHECKED_IN');
        $this->postJson('/api/appointments/'.$id.'/start', [], $this->auth('PATIENT'))->assertOk()->assertJsonPath('status', 'IN_PROGRESS');
        $this->postJson('/api/appointments/'.$id.'/cancel', [], $this->auth())
            ->assertStatus(400)->assertContent('Paid appointments cannot be cancelled');
        $this->getJson('/api/appointments/999', $this->auth())->assertNotFound()->assertContent('Appointment not found with id: 999');
    }

    public function test_scheduler_expires_only_pending_reservations_and_is_registered_every_minute(): void
    {
        $data = $this->createBooking();
        $schedule = Appointment::find($data['appointmentId'])->doctorSchedule;
        $otherPatient = Patient::create(['full_name' => 'Paid patient']);
        $paid = $this->postJson('/api/appointments', $this->booking($schedule, ['patientId' => $otherPatient->patient_id]), $this->auth())
            ->assertCreated()->json();
        $this->postJson('/api/appointments/'.$paid['appointmentId'].'/confirm-payment-manual', ['receiptNumber' => 'PAID'], $this->auth())->assertOk();
        Carbon::setTestNow(Carbon::parse('2026-11-17 09:10:00'));
        $this->artisan('appointments:expire-payment-reservations')->assertExitCode(0);
        $this->assertSame('PAYMENT_EXPIRED', Appointment::first()->getAttribute('status')->value);
        $this->assertSame('CONFIRMED', Appointment::find($paid['appointmentId'])->getAttribute('status')->value);
        $this->assertSame(1, Appointment::first()->doctorSchedule->current_booking_count);
        $this->assertSame('UNPAID', PaymentRecord::first()->getAttribute('payment_status')->value);
        $this->artisan('appointments:expire-payment-reservations')->assertExitCode(0);
        $this->assertSame(1, Appointment::first()->doctorSchedule->current_booking_count);
        $event = collect(Schedule::events())->first(fn ($event) => str_contains($event->command ?? '', 'appointments:expire-payment-reservations'));
        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_failure_after_reserving_slot_rolls_back_all_booking_writes(): void
    {
        $repository = $this->createMock(PaymentRecordRepository::class);
        $repository->method('save')->willThrowException(new RuntimeException('payment write failed'));
        $this->app->instance(PaymentRecordRepository::class, $repository);
        $schedule = $this->schedule();
        $this->postJson('/api/appointments', $this->booking($schedule), $this->auth())
            ->assertStatus(500)->assertContent('An unexpected error occurred: payment write failed');
        $this->assertSame(0, Appointment::count());
        $this->assertSame(0, PaymentRecord::count());
        $this->assertSame(0, $schedule->fresh()->current_booking_count);
        $this->assertSame(0, $schedule->fresh()->last_queue_number);
        $this->assertSame(0, $schedule->fresh()->version_number);
    }

    public function test_webhook_dispatch_preserves_content_priority_with_payment_service_stub(): void
    {
        $service = new class
        {
            public array $calls = [];

            public function confirmMedicalRecordPaymentFromSepayWebhook(array $request, ?string $secret): array
            {
                $this->calls[] = ['MR', $secret];

                return ['medicalRecordId' => 12, 'requestCode' => 'MR-12'];
            }

            public function confirmPrescriptionPaymentFromSepayWebhook(array $request, ?string $secret): array
            {
                $this->calls[] = ['PR', $secret];

                return ['prescriptionId' => 7, 'requestCode' => 'PR-7'];
            }
        };
        $this->app->instance('App\\Modules\\Payment\\Services\\PaymentRecordService', $service);
        $headers = ['Authorization' => 'Apikey webhook-test-secret'];
        $this->postJson('/api/appointments/sepay/webhook', ['content' => 'APT-12345678 PR-7 MR-12'], $headers)
            ->assertOk()->assertExactJson(['success' => true, 'medicalRecordId' => 12, 'requestCode' => 'MR-12']);
        $this->postJson('/api/appointments/sepay/webhook', ['content' => 'PR-7', 'description' => 'MR-12'], $headers)
            ->assertOk()->assertExactJson(['success' => true, 'prescriptionId' => 7, 'requestCode' => 'PR-7']);
        $this->assertSame([['MR', 'webhook-test-secret'], ['PR', 'webhook-test-secret']], $service->calls);
    }

    public function test_invalid_path_and_status_keep_java_conversion_errors(): void
    {
        $this->getJson('/api/appointments/abc', $this->auth())->assertStatus(500)
            ->assertContent('An unexpected error occurred: Method parameter \'id\': Failed to convert value of type \'java.lang.String\' to required type \'java.lang.Long\'; For input string: "abc"');
        $this->getJson('/api/appointments?status=invalid', $this->auth())->assertStatus(500)
            ->assertHeader('Content-Type', 'text/plain;charset=UTF-8');
    }

    public function test_walk_in_database_failure_rolls_back_reservation_and_all_payment_writes(): void
    {
        $schedule = $this->schedule();
        $body = $this->booking($schedule, ['receivedAmount' => '150000.00', 'note' => str_repeat('x', 501)]);
        $this->postJson('/api/appointments/walk-in', $body, $this->auth('RECEPTIONIST'))
            ->assertStatus(400)->assertContent('Request violates database constraints. Please check unique fields, enum values and data types');
        $this->assertSame(0, Appointment::count());
        $this->assertSame(0, PaymentRecord::count());
        $this->assertSame(0, PaymentTransaction::count());
        $this->assertSame(0, $schedule->fresh()->current_booking_count);
    }

    #[DataProvider('transactionDates')]
    public function test_transaction_date_matches_offset_datetime_parsing(string $input, string $expected): void
    {
        $data = $this->createBooking();
        $this->postJson('/api/appointments/sepay/webhook', $this->webhook($data, ['transactionDate' => $input]), ['X-Secret-Key' => 'webhook-test-secret'])->assertOk();
        $this->assertSame($expected, PaymentTransaction::first()->getAttribute('transaction_date')->format('Y-m-d H:i:s'));
    }

    public static function transactionDates(): iterable
    {
        yield 'lowercase ISO markers' => ['2026-11-17t10:30:00z', '2026-11-17 10:30:00'];
        yield 'hour-only offset' => ['2026-11-17T10:30+07', '2026-11-17 10:30:00'];
        yield 'invalid offset falls back to now' => ['2026-11-17T10:30:00+19:00', '2026-11-17 09:00:00'];
        yield 'nanosecond fraction' => ['2026-11-17T10:30:00.123456789+07:00', '2026-11-17 10:30:00'];
    }

    public function test_listing_orders_newest_first_and_keeps_all_java_filters(): void
    {
        $first = $this->createBooking();
        $otherPatient = Patient::create(['full_name' => 'Another patient']);
        $schedule = $this->schedule(['schedule_date' => '2026-11-19']);
        Carbon::setTestNow(Carbon::parse('2026-11-17 09:01:00'));
        $second = $this->postJson('/api/appointments', $this->booking($schedule, ['patientId' => $otherPatient->patient_id]), $this->auth())
            ->assertCreated()->json();
        $response = $this->getJson('/api/appointments', $this->auth())->assertOk();
        $this->assertSame([$second['appointmentId'], $first['appointmentId']], array_column($response->json(), 'appointmentId'));
        $this->getJson('/api/appointments?patientId='.$this->patient->patient_id, $this->auth())->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.appointmentId', $first['appointmentId']);
        $this->getJson('/api/appointments?doctorScheduleId='.$schedule->doctor_schedule_id, $this->auth())->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.appointmentId', $second['appointmentId']);
        $this->getJson('/api/appointments?doctorId=999', $this->auth())->assertOk()->assertExactJson([]);
        $this->postJson('/api/appointments/'.$first['appointmentId'].'/cancel', [], $this->auth())->assertOk();
        $this->getJson('/api/appointments?status=PENDING', $this->auth())->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.appointmentId', $second['appointmentId']);
    }
}
