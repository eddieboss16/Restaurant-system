<?php

namespace App\Console\Commands;

use App\Models\Payment;
use Illuminate\Console\Command;

class ExpireStuckStkPayments extends Command
{
    protected $signature = 'mpesa:expire-stuck-stk {--minutes=5 : Mark pending STK payments older than this as failed}';

    protected $description = 'Mark M-Pesa STK push payments stuck in pending status as failed (so the session unblocks).';

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');
        $cutoff = now()->subMinutes($minutes);

        $expired = Payment::query()
            ->where('method', 'mpesa')
            ->where('status', 'pending')
            ->whereNotNull('mpesa_checkout_request_id')
            ->where('created_at', '<', $cutoff)
            ->update([
                'status' => 'failed',
                'mpesa_result_desc' => "Timed out waiting for Daraja callback (>{$minutes} min).",
            ]);

        if ($expired > 0) {
            $this->info("Marked {$expired} stuck STK payment(s) as failed.");
        }

        return self::SUCCESS;
    }
}
