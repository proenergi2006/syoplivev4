<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
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
        /*
        |--------------------------------------------------------------------------
        | Force HTTPS berdasarkan environment
        |--------------------------------------------------------------------------
        |
        | Lokal:
        | APP_FORCE_HTTPS=false
        |
        | Production:
        | APP_FORCE_HTTPS=true
        |
        */
        if (config('app.force_https')) {
            URL::forceScheme('https');
        }
    }
}
