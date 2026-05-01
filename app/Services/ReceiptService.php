<?php

namespace App\Services;

use App\Models\CustomerSession;
use App\Models\PrintJob;

class ReceiptService
{
    /**
     * Build the receipt payload and enqueue a print job for the bridge to
     * pick up. No-op when print.auto_queue is false. Returns the PrintJob
     * if queued, null if skipped.
     */
    public function queueForSession(CustomerSession $session, ?int $queuedBy = null): ?PrintJob
    {
        if (! config('print.auto_queue', true)) {
            return null;
        }

        return PrintJob::create([
            'session_id' => $session->id,
            'queued_by' => $queuedBy,
            'payload' => $this->buildForSession($session),
            'status' => 'pending',
        ]);
    }


    /**
     * Build a printer-agnostic receipt structure for a paid (or about-to-be-paid)
     * session. The bridge translates this into ESC/POS bytes -- the PHP side
     * doesn't know about printer commands.
     */
    public function buildForSession(CustomerSession $session): array
    {
        $session->loadMissing(['orders.menuItem:id,name', 'payment', 'waiter:id,name']);

        $items = $session->orders
            ->filter(fn ($o) => $o->status !== 'cancelled')
            ->map(fn ($o) => [
                'name' => $o->menuItem?->name ?? 'unknown',
                'quantity' => (int) $o->quantity,
                'unit_price' => (float) $o->unit_price,
                'line_total' => (float) $o->quantity * (float) $o->unit_price,
            ])
            ->values()
            ->all();

        $total = array_sum(array_column($items, 'line_total'));

        return [
            'header' => [
                'name' => config('app.name', 'Restaurant'),
                'subtitle' => null,
            ],
            'meta' => [
                'session_id' => $session->id,
                'customer_label' => $session->customer_label,
                'waiter' => $session->waiter?->name,
                'served_at' => optional($session->closed_at ?? $session->opened_at)?->toIso8601String(),
            ],
            'items' => $items,
            'totals' => [
                'subtotal' => $total,
                'total' => $total,
            ],
            'payment' => $session->payment ? [
                'method' => $session->payment->method,
                'amount' => (float) $session->payment->amount,
                'mpesa_code' => $session->payment->mpesa_code,
            ] : null,
            'footer' => [
                'thank_you' => 'Asante! Karibu tena.',
            ],
        ];
    }
}
