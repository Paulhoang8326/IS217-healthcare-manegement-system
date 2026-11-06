<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        $files = glob(app_path('Modules/*/routes.php')) ?: [];
        sort($files);

        foreach ($files as $file) {
            Route::middleware('api')->prefix('api')->group($file);
        }
    }
}
