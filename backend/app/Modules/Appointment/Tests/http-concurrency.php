<?php

use App\Modules\Appointment\Tests\AppointmentHttpConcurrency;
use Illuminate\Contracts\Console\Kernel;

try {
    $root = dirname(__DIR__, 4);
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $options = getopt('', ['base-url:', 'secondary-base-url:', 'iterations:']);
    $iterations = filter_var($options['iterations'] ?? 3, FILTER_VALIDATE_INT);
    $secondary = $options['secondary-base-url'] ?? null;
    if (! is_string($options['base-url'] ?? null) || ($secondary !== null && ! is_string($secondary)) || $iterations === false) {
        throw new InvalidArgumentException('Usage: php app/Modules/Appointment/Tests/http-concurrency.php --base-url=http://127.0.0.1:8000 [--secondary-base-url=http://127.0.0.1:8001] --iterations=3');
    }
    $result = (new AppointmentHttpConcurrency($options['base-url'], $iterations, $secondary))->run();
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
} catch (Throwable $e) {
    fwrite(STDERR, json_encode(['success' => false, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR)."\n");
    exit(1);
}
