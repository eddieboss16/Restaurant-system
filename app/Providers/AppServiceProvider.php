<?php

namespace App\Providers;

use App\Services\MpesaService;
use App\Services\WhatsappService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MpesaService::class, fn ($app) => new MpesaService($app['config']->get('mpesa')));
        $this->app->singleton(WhatsappService::class, fn ($app) => new WhatsappService($app['config']->get('whatsapp')));
    }

    public function boot(): void
    {
        //
    }
}
