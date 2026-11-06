<?php

namespace App\Providers;

use App\Shared\Auth\CurrentUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $defaultUserResolver = Auth::userResolver();
        Auth::resolveUsersUsing(static function ($guard = null) use ($defaultUserResolver) {
            return request()->is('api', 'api/*')
                ? CurrentUser::fromRequest()
                : $defaultUserResolver($guard);
        });
    }
}
