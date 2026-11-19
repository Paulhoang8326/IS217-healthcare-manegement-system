<?php

use App\Modules\Appointment\Services\AppointmentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 4);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
Carbon::setTestNow(Carbon::parse('2026-11-17 09:00:00'));
if (DB::connection()->getDatabaseName() !== 'healthcare_test') {
    throw new RuntimeException('Booking worker requires the dedicated test database');
}
$payload = json_decode(base64_decode($argv[2]), true, 512, JSON_THROW_ON_ERROR);
fwrite(STDOUT, "START\n");
fflush(STDOUT);
try {
    $result = DB::transaction(function () use ($app, $payload, $argv): array {
        $response = $app->make(AppointmentService::class)->create($payload);
        if ($argv[1] === 'hold') {
            fwrite(STDOUT, "READY\n");
            fflush(STDOUT);
            if (trim((string) fgets(STDIN)) !== 'commit') {
                throw new RuntimeException('Booking worker was not released');
            }
        }

        return $response;
    });
    fwrite(STDOUT, 'RESULT '.json_encode(['success' => true, 'appointmentId' => $result['appointmentId']], JSON_THROW_ON_ERROR)."\n");
} catch (Throwable $e) {
    fwrite(STDOUT, 'RESULT '.json_encode(['success' => false, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR)."\n");
}
