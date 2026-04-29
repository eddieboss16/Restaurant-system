<?php

namespace App\Console\Commands;

use App\Services\ReportService;
use App\Services\WhatsappService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use RuntimeException;

class SendDailySummary extends Command
{
    protected $signature = 'summary:send-daily
                            {--for= : Date (YYYY-MM-DD) to summarize; defaults to today}
                            {--to= : Override recipient phone (otherwise config WHATSAPP_OWNER_PHONE)}';

    protected $description = "Send today's P&L snapshot to the owner via WhatsApp Cloud API.";

    public function handle(ReportService $reports, WhatsappService $whatsapp): int
    {
        if (! $whatsapp->isEnabled()) {
            $this->info('WhatsApp delivery is disabled (WHATSAPP_ENABLED=false). Skipping.');
            return self::SUCCESS;
        }

        $date = $this->option('for')
            ? CarbonImmutable::parse($this->option('for'))
            : CarbonImmutable::today();

        $recipient = $this->option('to') ?: config('whatsapp.owner_recipient');
        if (empty($recipient)) {
            $this->error('No recipient phone configured. Set WHATSAPP_OWNER_PHONE or pass --to=');
            return self::FAILURE;
        }

        $snapshot = $reports->dailySnapshot($date);

        try {
            $template = config('whatsapp.daily_summary_template');
            if ($template) {
                $params = $this->templateParameters($snapshot);
                $whatsapp->sendTemplate($recipient, $template, $params, config('whatsapp.daily_summary_template_language'));
                $this->info("Sent daily summary template '{$template}' to {$recipient}.");
            } else {
                $body = $this->formatTextSummary($snapshot);
                $whatsapp->sendText($recipient, $body);
                $this->info("Sent daily summary text to {$recipient}.");
            }
        } catch (RuntimeException $e) {
            $this->error('WhatsApp send failed: '.$e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Template parameter order (must match the approved template's {{1}}..{{6}}):
     *   1: date         2: revenue    3: expenses
     *   4: net          5: sessions   6: top item line
     */
    private function templateParameters(array $snapshot): array
    {
        $top = $snapshot['top_items'][0] ?? null;
        return [
            $snapshot['label'],
            'KES '.number_format($snapshot['revenue']['current'], 2),
            'KES '.number_format($snapshot['expenses']['current'], 2),
            'KES '.number_format($snapshot['net']['current'], 2),
            (string) $snapshot['sessions_paid']['current'],
            $top ? $top['quantity'].'x '.$top['name'] : 'no sales',
        ];
    }

    private function formatTextSummary(array $snapshot): string
    {
        $lines = [
            '*Daily Summary*  '.$snapshot['label'],
            '',
            'Revenue: KES '.number_format($snapshot['revenue']['current'], 2).' (yesterday: '.number_format($snapshot['revenue']['previous'], 2).')',
            'Expenses: KES '.number_format($snapshot['expenses']['current'], 2),
            'Net:     KES '.number_format($snapshot['net']['current'], 2),
            'Sessions paid: '.$snapshot['sessions_paid']['current'],
            'Cash:  KES '.number_format($snapshot['by_method']['cash'], 2),
            'M-Pesa: KES '.number_format($snapshot['by_method']['mpesa'], 2),
            'Cancellations: '.$snapshot['cancellations'],
        ];

        if (! empty($snapshot['top_items'])) {
            $lines[] = '';
            $lines[] = '*Top items:*';
            foreach (array_slice($snapshot['top_items'], 0, 3) as $item) {
                $lines[] = '· '.$item['quantity'].'x '.$item['name'].' (KES '.number_format($item['revenue'], 2).')';
            }
        }

        return implode("\n", $lines);
    }
}
