<?php

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 4);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();
if (DB::connection()->getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'healthcare_test') {
    throw new RuntimeException('Payment worker requires the dedicated healthcare_test MySQL database');
}
$job = json_decode(base64_decode($argv[1]), true, 512, JSON_THROW_ON_ERROR);
Carbon::setTestNow(Carbon::parse($job['now']));
config(['sepay.webhook-secret' => 'webhook-test-secret', 'sepay.account-number' => '123456']);
DB::statement('SET SESSION innodb_lock_wait_timeout = 10');
$lockingQuery = static fn (string $sql): bool => str_contains($sql, '`'.($job['waitTable'] ?? 'appointment').'`')
    && str_contains(strtolower($sql), 'for update');
DB::connection()->beforeExecuting(static function (string $sql) use ($lockingQuery): void {
    if ($lockingQuery($sql)) {
        fwrite(STDOUT, "LOCKING\n");
        fflush(STDOUT);
    }
});
DB::listen(static function (QueryExecuted $event) use ($lockingQuery): void {
    if ($lockingQuery($event->sql)) {
        fwrite(STDOUT, "LOCKED\n");
        fflush(STDOUT);
    }
});
try {
    $result = DB::transaction(function () use ($app, $job): array {
        if ($job['operation'] === 'webhook') {
            $request = Request::create('/api/appointments/sepay/webhook', 'POST', server: [
                'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_SECRET_KEY' => 'webhook-test-secret',
            ], content: json_encode($job['payload'], JSON_THROW_ON_ERROR));
            $kernel = $app->make(HttpKernel::class);
            $response = $kernel->handle($request);
            $result = ['status' => $response->getStatusCode(), 'body' => $response->getContent()];
            $kernel->terminate($request, $response);
        } elseif ($job['operation'] === 'expire') {
            $result = ['exitCode' => $app->make(ConsoleKernel::class)->call('appointments:expire-payment-reservations')];
        } else {
            throw new InvalidArgumentException('Unknown payment worker operation');
        }
        if ($job['hold'] ?? false) {
            fwrite(STDOUT, "READY\n");
            fflush(STDOUT);
            if (trim((string) fgets(STDIN)) !== 'commit') {
                throw new RuntimeException('Payment worker was not released');
            }
        }

        return $result;
    });
    fwrite(STDOUT, 'RESULT '.json_encode($result, JSON_THROW_ON_ERROR)."\n");
} catch (Throwable $e) {
    fwrite(STDOUT, 'RESULT '.json_encode(['exception' => $e::class, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR)."\n");
    exit(1);
}
