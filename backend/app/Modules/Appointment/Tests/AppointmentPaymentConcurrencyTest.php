<?php

namespace App\Modules\Appointment\Tests;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\PaymentRecord;
use App\Models\PaymentTransaction;
use App\Modules\Appointment\Services\AppointmentService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Shared\PersonOneModuleTestCase;

class AppointmentPaymentConcurrencyTest extends PersonOneModuleTestCase
{
    use DatabaseMigrations;

    #[DataProvider('webhookRetries')]
    public function test_parallel_webhooks_record_payment_once(?int $firstId, ?int $secondId, int $secondStatus): void
    {
        $schedule = $this->schedule();
        $appointment = app(AppointmentService::class)->create($this->booking($schedule));
        [$first, $second] = $this->race($this->webhookJob($appointment, $firstId), $this->webhookJob($appointment, $secondId));

        $this->assertWebhookSuccess($first, $appointment);
        if ($secondStatus === 200) {
            $this->assertWebhookSuccess($second, $appointment);
        } else {
            $this->assertSame(['status' => 400, 'body' => 'Appointment is already confirmed'], $second);
        }
        $stored = Appointment::findOrFail($appointment['appointmentId']);
        $payment = $stored->paymentRecord;
        $this->assertSame('CONFIRMED', $stored->getAttribute('status')->value);
        $this->assertSame($firstId, $stored->sepay_transaction_id);
        $this->assertSame(1, $stored->version_number);
        $this->assertNotNull($stored->getAttribute('paid_at'));
        $this->assertNull($stored->getAttribute('cancelled_at'));
        $this->assertSame('PAID', $payment->getAttribute('payment_status')->value);
        $this->assertSame('150000.00', $payment->getAttribute('received_amount'));
        $this->assertSame(1, PaymentRecord::count());
        $this->assertSame(1, PaymentTransaction::count());
        $transaction = PaymentTransaction::firstOrFail();
        $this->assertSame($firstId === null ? null : (string) $firstId, $transaction->sepay_transaction_id);
        $this->assertSame($payment->payment_record_id, $transaction->payment_record_id);
        $this->assertSame('150000.00', $transaction->getAttribute('transfer_amount'));
        $this->assertSame(1, $schedule->fresh()->current_booking_count);
        $this->assertSame(1, $schedule->fresh()->last_queue_number);
        $this->assertSame(1, $schedule->fresh()->version_number);
    }

    public static function webhookRetries(): iterable
    {
        yield 'same SePay transaction ID' => [901, 901, 200];
        yield 'different SePay transaction IDs' => [901, 902, 400];
        yield 'no SePay transaction ID' => [null, null, 400];
    }

    #[DataProvider('expiryOrders')]
    public function test_payment_and_expiry_do_not_overwrite_each_other(bool $paymentFirst): void
    {
        $schedule = $this->schedule();
        $appointment = app(AppointmentService::class)->create($this->booking($schedule));
        $webhook = $this->webhookJob($appointment, 903);
        $expire = ['operation' => 'expire', 'now' => '2026-11-17 09:10:00'];
        if ($paymentFirst) {
            [$paymentResult, $expiryResult] = $this->race($webhook, $expire);
            $this->assertWebhookSuccess($paymentResult, $appointment);
        } else {
            $webhook['now'] = '2026-11-17 09:10:00';
            [$expiryResult, $paymentResult] = $this->race($expire, $webhook);
            $this->assertSame(['status' => 400, 'body' => 'Only pending appointments can be confirmed for payment'], $paymentResult);
        }
        $this->assertSame(['exitCode' => 0], $expiryResult);
        $stored = Appointment::findOrFail($appointment['appointmentId']);
        $payment = $stored->paymentRecord;
        $this->assertSame($paymentFirst ? 'CONFIRMED' : 'PAYMENT_EXPIRED', $stored->getAttribute('status')->value);
        $this->assertSame($paymentFirst ? 'PAID' : 'UNPAID', $payment->getAttribute('payment_status')->value);
        $this->assertSame($paymentFirst ? '150000.00' : '0.00', $payment->getAttribute('received_amount'));
        $this->assertSame($paymentFirst ? 1 : 0, PaymentTransaction::count());
        $this->assertSame($paymentFirst ? 903 : null, $stored->sepay_transaction_id);
        $this->assertSame($paymentFirst, $stored->getAttribute('paid_at') !== null);
        $this->assertSame($paymentFirst, $payment->getAttribute('paid_at') !== null);
        $this->assertSame(! $paymentFirst, $stored->getAttribute('cancelled_at') !== null);
        $this->assertSame(1, $stored->version_number);
        $this->assertSame($paymentFirst ? 1 : 0, $schedule->fresh()->current_booking_count);
        $this->assertSame(1, $schedule->fresh()->last_queue_number);
        $this->assertSame($paymentFirst ? 1 : 2, $schedule->fresh()->version_number);

        Carbon::setTestNow(Carbon::parse('2026-11-17 09:10:00'));
        app(AppointmentService::class)->expirePendingPaymentReservations();
        $this->assertSame(1, $stored->fresh()->version_number);
        $this->assertSame($paymentFirst ? 1 : 0, $schedule->fresh()->current_booking_count);
        $this->assertSame($paymentFirst ? 1 : 2, $schedule->fresh()->version_number);
    }

    public static function expiryOrders(): iterable
    {
        yield 'payment locks first, expiry scans at deadline' => [true];
        yield 'expiry locks first at deadline, payment waits' => [false];
    }

    public function test_same_transaction_for_two_appointments_rolls_back_losing_payment(): void
    {
        $schedule = $this->schedule();
        $service = app(AppointmentService::class);
        $firstAppointment = $service->create($this->booking($schedule));
        $otherPatient = Patient::create(['full_name' => 'Second payment patient']);
        $secondAppointment = $service->create($this->booking($schedule, ['patientId' => $otherPatient->patient_id]));
        $secondJob = $this->webhookJob($secondAppointment, 904);
        $secondJob['waitTable'] = 'doctor_schedule';
        [$first, $second] = $this->race($this->webhookJob($firstAppointment, 904), $secondJob);

        $this->assertWebhookSuccess($first, $firstAppointment);
        $this->assertSame(['status' => 400, 'body' => 'Payment confirmation conflict detected'], $second);
        $winner = Appointment::findOrFail($firstAppointment['appointmentId']);
        $loser = Appointment::findOrFail($secondAppointment['appointmentId']);
        $this->assertSame('CONFIRMED', $winner->getAttribute('status')->value);
        $this->assertSame('PAID', $winner->paymentRecord->getAttribute('payment_status')->value);
        $this->assertSame('150000.00', $winner->paymentRecord->getAttribute('received_amount'));
        $this->assertSame('PENDING', $loser->getAttribute('status')->value);
        $this->assertNull($loser->sepay_transaction_id);
        $this->assertNull($loser->getAttribute('paid_at'));
        $this->assertNull($loser->getAttribute('cancelled_at'));
        $this->assertSame(0, $loser->version_number);
        $this->assertSame('UNPAID', $loser->paymentRecord->getAttribute('payment_status')->value);
        $this->assertSame('0.00', $loser->paymentRecord->getAttribute('received_amount'));
        $this->assertNull($loser->paymentRecord->getAttribute('paid_at'));
        $this->assertSame(2, PaymentRecord::count());
        $this->assertSame(1, PaymentTransaction::count());
        $this->assertSame($winner->paymentRecord->payment_record_id, PaymentTransaction::firstOrFail()->payment_record_id);
        $this->assertSame(2, $schedule->fresh()->current_booking_count);
        $this->assertSame(2, $schedule->fresh()->last_queue_number);
        $this->assertSame(2, $schedule->fresh()->version_number);
    }

    private function webhookJob(array $appointment, ?int $transactionId): array
    {
        return ['operation' => 'webhook', 'now' => '2026-11-17 09:09:59', 'payload' => [
            'id' => $transactionId, 'transferType' => 'in', 'transferAmount' => 150000, 'accountNumber' => '123456',
            'content' => 'Payment '.$appointment['appointmentCode'],
        ]];
    }

    private function race(array $firstJob, array $secondJob): array
    {
        $input = new InputStream;
        $first = $this->worker($firstJob + ['hold' => true]);
        $first->setInput($input);
        $second = $this->worker($secondJob);
        try {
            $first->start();
            $this->assertTrue($first->waitUntil(fn ($type, $data) => str_contains($data, "READY\n")), $first->getOutput().$first->getErrorOutput());
            $second->start();
            $this->assertTrue($second->waitUntil(fn ($type, $data) => str_contains($data, "LOCKING\n")), $second->getOutput().$second->getErrorOutput());
            usleep(250000);
            $this->assertTrue($second->isRunning(), $second->getOutput().$second->getErrorOutput());
            $this->assertStringNotContainsString("LOCKED\n", $second->getOutput(), 'The locking query must not complete before the first transaction is released.');
            $input->write("commit\n");
            $input->close();
            $first->wait();
            $second->wait();
            $this->assertStringContainsString("LOCKED\n", $second->getOutput());

            return [$this->workerResult($first), $this->workerResult($second)];
        } finally {
            $input->close();
            $first->stop(0);
            $second->stop(0);
        }
    }

    private function worker(array $job): Process
    {
        return new Process([PHP_BINARY, __DIR__.'/payment-worker.php', base64_encode(json_encode($job, JSON_THROW_ON_ERROR))],
            base_path(), timeout: 20);
    }

    private function workerResult(Process $worker): array
    {
        $this->assertSame(0, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
        $this->assertSame(1, preg_match('/^RESULT (.+)$/m', $worker->getOutput(), $match), $worker->getOutput());

        return json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
    }

    private function assertWebhookSuccess(array $result, array $appointment): void
    {
        $this->assertSame(200, $result['status'], $result['body']);
        $this->assertSame(['success' => true, 'appointmentId' => $appointment['appointmentId'], 'appointmentCode' => $appointment['appointmentCode']],
            json_decode($result['body'], true, 512, JSON_THROW_ON_ERROR));
    }
}
