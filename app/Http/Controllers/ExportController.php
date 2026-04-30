<?php

namespace App\Http\Controllers;

use App\Models\CustomerSession;
use App\Models\Expense;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    /**
     * Stream a CSV of paid sessions in the date range. One row per session,
     * items concatenated. Streamed (not buffered) so a 6-month export
     * doesn't OOM the PHP process.
     */
    public function paidSessions(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $from = $data['from'] ?? now()->startOfMonth()->toDateString();
        $to = $data['to'] ?? now()->toDateString();

        $filename = "paid-sessions_{$from}_to_{$to}.csv";

        $query = CustomerSession::query()
            ->with(['waiter:id,name', 'payment', 'orders.menuItem:id,name'])
            ->where('status', 'paid')
            ->whereDate('closed_at', '>=', $from)
            ->whereDate('closed_at', '<=', $to)
            ->orderBy('closed_at');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['session_id', 'closed_at', 'customer_label', 'waiter', 'items', 'total_kes', 'method', 'mpesa_code']);

            $query->chunk(200, function ($sessions) use ($out) {
                foreach ($sessions as $s) {
                    $items = $s->orders
                        ->filter(fn ($o) => $o->status !== 'cancelled')
                        ->map(fn ($o) => $o->quantity.'x '.($o->menuItem?->name ?? 'unknown'))
                        ->implode(', ');
                    $total = $s->orders
                        ->filter(fn ($o) => $o->status !== 'cancelled')
                        ->sum(fn ($o) => $o->quantity * $o->unit_price);

                    fputcsv($out, [
                        $s->id,
                        optional($s->closed_at)?->toDateTimeString(),
                        $s->customer_label,
                        $s->waiter?->name,
                        $items,
                        number_format((float) $total, 2, '.', ''),
                        $s->payment?->method,
                        $s->payment?->mpesa_code,
                    ]);
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function expenses(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $from = $data['from'] ?? now()->startOfMonth()->toDateString();
        $to = $data['to'] ?? now()->toDateString();

        $filename = "expenses_{$from}_to_{$to}.csv";

        $query = Expense::query()
            ->with('recordedBy:id,name')
            ->whereBetween('incurred_on', [$from, $to])
            ->orderBy('incurred_on');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'incurred_on', 'category', 'description', 'amount_kes', 'recorded_by']);

            $query->chunk(200, function ($expenses) use ($out) {
                foreach ($expenses as $e) {
                    fputcsv($out, [
                        $e->id,
                        $e->incurred_on->toDateString(),
                        $e->category,
                        $e->description,
                        number_format((float) $e->amount, 2, '.', ''),
                        $e->recordedBy?->name,
                    ]);
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
