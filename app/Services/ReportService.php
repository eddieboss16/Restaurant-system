<?php

namespace App\Services;

use App\Models\CancellationLog;
use App\Models\CustomerSession;
use App\Models\Expense;
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
        $current = $date ?? CarbonImmutable::today();
        $previous = $current->subDay();

        $revenueCurrent = $this->revenueOn($current);
        $revenuePrevious = $this->revenueOn($previous);
        $expensesCurrent = $this->expensesBetween($current->toDateString(), $current->toDateString());
        $expensesPrevious = $this->expensesBetween($previous->toDateString(), $previous->toDateString());

        return [
            'period' => 'day',
            'label' => $current->toDateString(),
            'revenue' => [
                'current' => $revenueCurrent,
                'previous' => $revenuePrevious,
            ],
            'sessions_paid' => [
                'current' => $this->sessionsPaidOn($current),
                'previous' => $this->sessionsPaidOn($previous),
            ],
            'by_method' => $this->revenueByMethodOn($current),
            'top_items' => $this->topItemsOn($current, limit: 5),
            'cancellations' => $this->cancellationsOn($current),
            'expenses' => [
                'current' => $expensesCurrent,
                'previous' => $expensesPrevious,
            ],
            'expenses_by_category' => $this->expensesByCategoryBetween($current->toDateString(), $current->toDateString()),
            'net' => [
                'current' => $revenueCurrent - $expensesCurrent,
                'previous' => $revenuePrevious - $expensesPrevious,
            ],
        ];
    }

    /**
     * Snapshot for the calendar month containing $date (defaults to current
     * month). Same shape as dailySnapshot, with previous-month comparison
     * and a wider top-items list.
     */
    public function monthlySnapshot(?CarbonImmutable $date = null): array
    {
        $current = ($date ?? CarbonImmutable::today())->startOfMonth();
        $previous = $current->subMonth();
        $currentEnd = $current->endOfMonth();
        $previousEnd = $previous->endOfMonth();

        $revenueCurrent = $this->revenueIn($this->monthBounds($current));
        $revenuePrevious = $this->revenueIn($this->monthBounds($previous));
        $expensesCurrent = $this->expensesBetween($current->toDateString(), $currentEnd->toDateString());
        $expensesPrevious = $this->expensesBetween($previous->toDateString(), $previousEnd->toDateString());

        return [
            'period' => 'month',
            'label' => $current->format('Y-m'),
            'revenue' => [
                'current' => $revenueCurrent,
                'previous' => $revenuePrevious,
            ],
            'sessions_paid' => [
                'current' => $this->sessionsPaidIn($this->monthBounds($current)),
                'previous' => $this->sessionsPaidIn($this->monthBounds($previous)),
            ],
            'by_method' => $this->revenueByMethodIn($this->monthBounds($current)),
            'top_items' => $this->topItemsIn($this->monthBounds($current), limit: 10),
            'cancellations' => $this->cancellationsIn($this->monthBounds($current)),
            'expenses' => [
                'current' => $expensesCurrent,
                'previous' => $expensesPrevious,
            ],
            'expenses_by_category' => $this->expensesByCategoryBetween($current->toDateString(), $currentEnd->toDateString()),
            'net' => [
                'current' => $revenueCurrent - $expensesCurrent,
                'previous' => $revenuePrevious - $expensesPrevious,
            ],
        ];
    }

    /**
     * Per-waiter today snapshot. Cheap; safe to call on every dashboard load.
     */
    public function waiterToday(User $waiter, ?CarbonImmutable $date = null): array
    {
        $today = $date ?? CarbonImmutable::today();
        $bounds = $this->dayBounds($today);

        $sessions = CustomerSession::query()
            ->with([
                'orders' => fn ($q) => $q->where('status', '!=', 'cancelled'),
                'orders.menuItem:id,name',
                'payment',
            ])
            ->where('waiter_id', $waiter->id)
            ->where('status', 'paid')
            ->whereBetween('closed_at', $bounds)
            ->orderByDesc('closed_at')
            ->get();

        $revenue = (float) Payment::query()
            ->where('collected_by', $waiter->id)
            ->where('status', 'completed')
            ->whereBetween('confirmed_at', $bounds)
            ->sum('amount');

        return [
            'date' => $today->toDateString(),
            'sessions_paid' => $sessions->count(),
            'revenue_collected' => $revenue,
            'sessions' => $sessions->map(fn ($s) => [
                'id' => $s->id,
                'customer_label' => $s->customer_label,
                'closed_at' => $s->closed_at,
                'total' => (float) $s->orders->sum(fn ($o) => $o->quantity * $o->unit_price),
                'method' => $s->payment?->method,
                'mpesa_code' => $s->payment?->mpesa_code,
                'items' => $s->orders->map(fn ($o) => [
                    'name' => $o->menuItem->name,
                    'quantity' => $o->quantity,
                ])->all(),
            ])->all(),
        ];
    }

    private function revenueOn(CarbonImmutable $date): float
    {
        return $this->revenueIn($this->dayBounds($date));
    }

    private function sessionsPaidOn(CarbonImmutable $date): int
    {
        return $this->sessionsPaidIn($this->dayBounds($date));
    }

    private function revenueByMethodOn(CarbonImmutable $date): array
    {
        return $this->revenueByMethodIn($this->dayBounds($date));
    }

    private function topItemsOn(CarbonImmutable $date, int $limit): array
    {
        return $this->topItemsIn($this->dayBounds($date), $limit);
    }

    private function cancellationsOn(CarbonImmutable $date): int
    {
        return $this->cancellationsIn($this->dayBounds($date));
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}  $bounds
     */
    private function revenueIn(array $bounds): float
    {
        return (float) Payment::query()
            ->where('status', 'completed')
            ->whereBetween('confirmed_at', $bounds)
            ->sum('amount');
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}  $bounds
     */
    private function sessionsPaidIn(array $bounds): int
    {
        return CustomerSession::query()
            ->where('status', 'paid')
            ->whereBetween('closed_at', $bounds)
            ->count();
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}  $bounds
     */
    private function revenueByMethodIn(array $bounds): array
    {
        $rows = Payment::query()
            ->selectRaw('method, SUM(amount) as total')
            ->where('status', 'completed')
            ->whereBetween('confirmed_at', $bounds)
            ->groupBy('method')
            ->get();

        $byMethod = ['cash' => 0.0, 'mpesa' => 0.0];
        foreach ($rows as $row) {
            $byMethod[$row->method] = (float) $row->total;
        }
        return $byMethod;
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}  $bounds
     */
    private function topItemsIn(array $bounds, int $limit): array
    {
        return Order::query()
            ->join('customer_sessions', 'orders.session_id', '=', 'customer_sessions.id')
            ->join('menu_items', 'orders.menu_item_id', '=', 'menu_items.id')
            ->where('orders.status', 'delivered')
            ->where('customer_sessions.status', 'paid')
            ->whereBetween('customer_sessions.closed_at', $bounds)
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

    /**
     * @param  array{0: Carbon, 1: Carbon}  $bounds
     */
    private function cancellationsIn(array $bounds): int
    {
        return CancellationLog::query()
            ->whereBetween('cancelled_at', $bounds)
            ->count();
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function dayBounds(CarbonImmutable $date): array
    {
        return [Carbon::instance($date->startOfDay()), Carbon::instance($date->endOfDay())];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function monthBounds(CarbonImmutable $monthStart): array
    {
        return [Carbon::instance($monthStart->startOfMonth()), Carbon::instance($monthStart->endOfMonth())];
    }

    private function expensesBetween(string $fromDate, string $toDate): float
    {
        return (float) Expense::query()
            ->whereBetween('incurred_on', [$fromDate, $toDate])
            ->sum('amount');
    }

    private function expensesByCategoryBetween(string $fromDate, string $toDate): array
    {
        $rows = Expense::query()
            ->selectRaw('category, SUM(amount) as total')
            ->whereBetween('incurred_on', [$fromDate, $toDate])
            ->groupBy('category')
            ->get();

        $byCategory = array_fill_keys(Expense::CATEGORIES, 0.0);
        foreach ($rows as $row) {
            $byCategory[$row->category] = (float) $row->total;
        }
        return $byCategory;
    }
}
