<?php

namespace App\Console\Commands;

use App\Models\PrintJob;
use Illuminate\Console\Command;

class ResetStuckPrintJobs extends Command
{
    protected $signature = 'prints:reset-stuck {--minutes=2 : Reset jobs stuck in "printing" longer than this back to pending}';

    protected $description = 'Reset print jobs that have been "printing" for too long back to "pending" so the bridge picks them up again.';

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');
        $cutoff = now()->subMinutes($minutes);

        $reset = PrintJob::query()
            ->where('status', 'printing')
            ->where('updated_at', '<', $cutoff)
            ->update(['status' => 'pending']);

        if ($reset > 0) {
            $this->info("Reset {$reset} stuck print job(s) back to pending.");
        }

        return self::SUCCESS;
    }
}
