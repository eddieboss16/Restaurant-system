<?php

namespace App\Services;

use App\Models\CancellationLog;
use App\Models\CustomerSession;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

class ReportService
{
    /**
     * Snapshot for the owner: revenue, sessions, payment-method split,
     * top items, cancellations -- with a yesterday comparison for context.
     */
    public function dailySnapshot(?CarbonImmutable $date = null): array
    {
        $today = $date ?? CarbonImmutable::today();
        $yesterday = $today->subDay();

        return [
            'date' => $today->toDateString(),
            'revenue' => [
                'today' => $this->revenueOn($today),
                'yesterday' => $this->revenueOn($yesterday),
            ],
            'sessions_paid' => [
                'today' => $this->sessionsPaidOn($today),
                'yesterday' => $this->sessionsPaidOn($yesterday),
            ],
            'by_method' => $this->revenueByMethodOn($today),
            'top_items' => $this->topItemsOn($today, limit: 5),
            'cancellations' => $this->cancellationsOn($today),
        ];
    }

    /**
     * Per-waiter today snapshot. Cheap; safe to call on every dashboard load.
     */
    public function waiterToday(User $waiter, ?CarbonImmutable $date = null): array
    {
        $today = $date ?? CarbonImmutable::today();

        $sessionsPaid = CustomerSession::query()
            ->where('waiter_id', $waiter->id)
            ->where('status', 'paid')
            ->whereBetween('closed_at', $this->dayBounds($today))
            ->count();

        $revenue = (float) Payment::query()
            ->where('collected_by', $waiter->id)
            ->where('status', 'completed')
            ->whereBetween('confirmed_at', $this->dayBounds($today))
            ->sum('amount');

        return [
            'date' => $today->toDateString(),
            'sessions_paid' => $sessionsPaid,
            'revenue_collected' => $revenue,
        ];
    }

    private function revenueOn(CarbonImmutable $date): float
    {
        return (float) Payment::query()
            ->where('status', 'completed')
            ->whereBetween('confirmed_at', $this->dayBounds($date))
            ->sum('amount');
    }

    private function sessionsPaidOn(CarbonImmutable $date): int
    {
        return CustomerSession::query()
            ->where('status', 'paid')
            ->whereBetween('closed_at', $this->dayBounds($date))
            ->count();
    }

    private function revenueByMethodOn(CarbonImmutable $date): array
    {
        $rows = Payment::query()
            ->selectRaw('method, SUM(amount) as total')
            ->where('status', 'completed')
            ->whereBetween('confirmed_at', $this->dayBounds($date))
            ->groupBy('method')
            ->get();

        $byMethod = ['cash' => 0.0, 'mpesa' => 0.0];
        foreach ($rows as $row) {
            $byMethod[$row->method] = (float) $row->total;
        }
        return $byMethod;
    }

    private function topItemsOn(CarbonImmutable $date, int $limit): array
    {
        // Orders that were actually delivered, on sessions paid today.
        return Order::query()
            ->join('customer_sessions', 'orders.session_id', '=', 'customer_sessions.id')
            ->join('menu_items', 'orders.menu_item_id', '=', 'menu_items.id')
            ->where('orders.status', 'delivered')
            ->where('customer_sessions.status', 'paid')
            ->whereBetween('customer_sessions.closed_at', $this->dayBounds($date))
            ->groupBy('menu_items.id', 'menu_items.name')
            ->selectRaw('menu_items.name as name, SUM(orders.quantity) as quantity, SUM(orders.quantity * orders.unit_price) as revenue')
            ->orderByDesc('quantity')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name,
                'quantity' => (int) $row->quantity,
                'revenue' => (float) $row->revenue,
            ])
            ->all();
    }

    private function cancellationsOn(CarbonImmutable $date): int
    {
        return CancellationLog::query()
            ->whereBetween('cancelled_at', $this->dayBounds($date))
            ->count();
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function dayBounds(CarbonImmutable $date): array
    {
        return [Carbon::instance($date->startOfDay()), Carbon::instance($date->endOfDay())];
    }
}
