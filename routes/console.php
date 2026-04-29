<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Free up sessions whose STK push never got a Daraja callback.
// Requires `php artisan schedule:work` (dev) or cron `schedule:run` every minute (prod).
Schedule::command('mpesa:expire-stuck-stk')->everyMinute();

// Daily P&L summary to the owner's WhatsApp at 11:59pm server-local.
// No-ops if WHATSAPP_ENABLED=false.
Schedule::command('summary:send-daily')->dailyAt('23:59');
