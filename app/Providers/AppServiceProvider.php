<?php

namespace App\Providers;

use App\Services\MpesaService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MpesaService::class, fn ($app) => new MpesaService($app['config']->get('mpesa')));
    }

    public function boot(): void
    {
        //
    }
}
