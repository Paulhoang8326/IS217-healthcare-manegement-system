<?php

namespace App\Modules\Appointment\Tests;

use App\Models\Appointment;
use App\Models\Patient;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Shared\PersonOneModuleTestCase;

class AppointmentConcurrencyTest extends PersonOneModuleTestCase
{
    use DatabaseMigrations;

    #[DataProvider('races')]
    public function test_concurrent_bookings_preserve_patient_and_capacity_locks(bool $samePatient, string $expectedError): void
    {
        $firstSchedule = $this->schedule(['max_capacity' => $samePatient ? 5 : 1]);
        $secondSchedule = $samePatient ? $this->schedule(['shift' => 'AFTERNOON']) : $firstSchedule;
        $otherPatient = $samePatient ? $this->patient : Patient::create(['full_name' => 'Patient Two']);
        $input = new InputStream;
        $first = $this->worker('hold', $this->booking($firstSchedule));
        $first->setInput($input);
        $second = $this->worker('run', $this->booking($secondSchedule, ['patientId' => $otherPatient->patient_id]));
        try {
            $first->start();
            $this->assertTrue($first->waitUntil(fn ($type, $data) => str_contains($data, 'READY')), $first->getErrorOutput());
            $second->start();
            $this->assertTrue($second->waitUntil(fn ($type, $data) => str_contains($data, 'START')), $second->getErrorOutput());
            usleep(250000);
            $this->assertTrue($second->isRunning(), 'The second transaction should wait while the first holds its row locks.');
            $input->write("commit\n");
            $input->close();
            $first->wait();
            $second->wait();
            $this->assertTrue($this->workerResult($first)['success']);
            $this->assertSame(['success' => false, 'message' => $expectedError], $this->workerResult($second));
            $this->assertSame(1, Appointment::count());
            $this->assertSame(1, $firstSchedule->fresh()->current_booking_count);
            $this->assertSame(1, $firstSchedule->fresh()->last_queue_number);
            if ($samePatient) {
                $this->assertSame(0, $secondSchedule->fresh()->current_booking_count);
            }
        } finally {
            $input->close();
            $first->stop(0);
            $second->stop(0);
        }
    }

    public static function races(): iterable
    {
        yield 'same patient, different schedules' => [true, 'Patient already has an active appointment'];
        yield 'different patients, last available slot' => [false, 'Doctor schedule is full'];
    }

    private function worker(string $mode, array $payload): Process
    {
        return new Process([PHP_BINARY, __DIR__.'/booking-worker.php', $mode, base64_encode(json_encode($payload, JSON_THROW_ON_ERROR))],
            base_path(), timeout: 20);
    }

    private function workerResult(Process $process): array
    {
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame(1, preg_match('/^RESULT (.+)$/m', $process->getOutput(), $match), $process->getOutput().$process->getErrorOutput());

        return json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
    }
}
