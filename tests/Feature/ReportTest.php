<?php

namespace Tests\Feature;

use App\Models\CancellationLog;
use App\Models\CustomerSession;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SeedsRestaurantData;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;
    use SeedsRestaurantData;

    private User $admin;
    private Carbon $today;
    private Carbon $yesterday;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRestaurantData();

        // Pin "now" to a fixed mid-day so day boundaries are unambiguous.
        $this->today = Carbon::create(2026, 4, 28, 14, 0, 0);
        $this->yesterday = $this->today->copy()->subDay();
        Carbon::setTestNow($this->today);

        $this->admin = User::factory()->admin()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function paidSession(User $waiter, Carbon $closedAt, array $items, string $method = 'cash', ?string $mpesaCode = null): CustomerSession
    {
        $session = CustomerSession::create([
            'waiter_id' => $waiter->id,
            'status' => 'paid',
            'opened_at' => $closedAt->copy()->subHour(),
            'closed_at' => $closedAt,
        ]);

        $total = 0.0;
        foreach ($items as [$menuKey, $qty]) {
            $price = (float) $this->menuItems[$menuKey]->price;
            Order::create([
                'session_id' => $session->id,
                'menu_item_id' => $this->menuItems[$menuKey]->id,
                'quantity' => $qty,
                'unit_price' => $price,
                'status' => 'delivered',
            ]);
            $total += $price * $qty;
        }

        Payment::create([
            'session_id' => $session->id,
            'method' => $method,
            'amount' => $total,
            'status' => 'completed',
            'mpesa_code' => $mpesaCode,
            'collected_by' => $waiter->id,
            'confirmed_at' => $closedAt,
        ]);

        return $session;
    }

    public function test_daily_report_returns_expected_structure_and_zero_state(): void
    {
        $payload = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/reports/today')
            ->assertOk()
            ->json();

        $this->assertSame('day', $payload['period']);
        $this->assertSame($this->today->toDateString(), $payload['label']);
        $this->assertEquals(0, $payload['revenue']['current']);
        $this->assertEquals(0, $payload['revenue']['previous']);
        $this->assertSame(0, $payload['sessions_paid']['current']);
        $this->assertEquals(['cash' => 0, 'mpesa' => 0], $payload['by_method']);
        $this->assertSame([], $payload['top_items']);
        $this->assertSame(0, $payload['cancellations']);
    }

    public function test_daily_report_aggregates_revenue_sessions_and_method_split(): void
    {
        $waiter = User::factory()->waiter()->create();

        // Today: chips×2 cash + soda×3 mpesa
        $this->paidSession($waiter, $this->today->copy()->setTime(10, 0), [['chips', 2]], 'cash');
        $this->paidSession($waiter, $this->today->copy()->setTime(12, 0), [['soda', 3]], 'mpesa', 'NLJ7RT61SV');

        // Yesterday: chips×1 cash
        $this->paidSession($waiter, $this->yesterday->copy()->setTime(13, 0), [['chips', 1]], 'cash');

        $payload = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/reports/today')
            ->assertOk()
            ->json();

        // Today = (150 × 2) + (80 × 3) = 540
        $this->assertEquals(540, $payload['revenue']['current']);
        // Yesterday = 150 × 1 = 150
        $this->assertEquals(150, $payload['revenue']['previous']);

        $this->assertSame(2, $payload['sessions_paid']['current']);
        $this->assertSame(1, $payload['sessions_paid']['previous']);

        $this->assertEquals(300, $payload['by_method']['cash']);
        $this->assertEquals(240, $payload['by_method']['mpesa']);
    }

    public function test_top_items_ranks_by_quantity_within_today_only(): void
    {
        $waiter = User::factory()->waiter()->create();

        $this->paidSession($waiter, $this->today->copy()->setTime(10, 0), [['chips', 5], ['soda', 2]]);
        $this->paidSession($waiter, $this->today->copy()->setTime(11, 0), [['soda', 4]]);
        // Yesterday's order for chips×100 must NOT appear.
        $this->paidSession($waiter, $this->yesterday->copy()->setTime(13, 0), [['chips', 100]]);

        $payload = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/reports/today')
            ->assertOk()
            ->json();

        $this->assertCount(2, $payload['top_items']);
        $this->assertSame('Soda', $payload['top_items'][0]['name']);
        $this->assertSame(6, $payload['top_items'][0]['quantity']);
        $this->assertSame('Chips', $payload['top_items'][1]['name']);
        $this->assertSame(5, $payload['top_items'][1]['quantity']);
    }

    public function test_pending_payments_do_not_count_as_revenue(): void
    {
        $waiter = User::factory()->waiter()->create();
        $session = CustomerSession::create([
            'waiter_id' => $waiter->id,
            'status' => 'served',
            'opened_at' => $this->today->copy()->subHour(),
        ]);
        Order::create([
            'session_id' => $session->id,
            'menu_item_id' => $this->menuItems['soda']->id,
            'quantity' => 1,
            'unit_price' => 80,
            'status' => 'delivered',
        ]);
        Payment::create([
            'session_id' => $session->id,
            'method' => 'mpesa',
            'amount' => 80,
            'status' => 'pending',
            'mpesa_checkout_request_id' => 'CR-pending',
            'collected_by' => $waiter->id,
        ]);

        $payload = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/reports/today')
            ->assertOk()
            ->json();

        $this->assertEquals(0, $payload['revenue']['current']);
        $this->assertSame(0, $payload['sessions_paid']['current']);
    }

    public function test_cancellations_count_today_only(): void
    {
        $waiter = User::factory()->waiter()->create();
        $session = CustomerSession::create([
            'waiter_id' => $waiter->id,
            'status' => 'ordered',
            'opened_at' => $this->today,
        ]);
        $order = Order::create([
            'session_id' => $session->id,
            'menu_item_id' => $this->menuItems['soda']->id,
            'quantity' => 1,
            'unit_price' => 80,
            'status' => 'cancelled',
        ]);

        CancellationLog::create([
            'order_id' => $order->id,
            'cancelled_by' => $waiter->id,
            'reason' => 'wrong order',
            'cancelled_at' => $this->today,
        ]);
        CancellationLog::create([
            'order_id' => $order->id,
            'cancelled_by' => $waiter->id,
            'reason' => 'old',
            'cancelled_at' => $this->yesterday,
        ]);

        $payload = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/reports/today')
            ->assertOk()
            ->json();

        $this->assertSame(1, $payload['cancellations']);
    }

    public function test_non_admin_cannot_access_daily_report(): void
    {
        $waiter = User::factory()->waiter()->create();

        $this->actingAs($waiter, 'sanctum')
            ->getJson('/api/reports/today')
            ->assertForbidden();
    }

    // ----- Monthly report -----

    public function test_monthly_report_aggregates_within_calendar_month_only(): void
    {
        $waiter = User::factory()->waiter()->create();
        $thisMonthDay1 = $this->today->copy()->startOfMonth()->setTime(10, 0);
        $thisMonthMid = $this->today->copy()->setTime(10, 0);
        $lastMonthEnd = $this->today->copy()->startOfMonth()->subDay()->setTime(15, 0);

        $this->paidSession($waiter, $thisMonthDay1, [['chips', 1]]);   // 150 in current month
        $this->paidSession($waiter, $thisMonthMid, [['soda', 2]]);     // 160 in current month
        $this->paidSession($waiter, $lastMonthEnd, [['chips', 5]]);    // 750 in previous month

        $payload = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/reports/month')
            ->assertOk()
            ->json();

        $this->assertSame('month', $payload['period']);
        $this->assertSame($this->today->format('Y-m'), $payload['label']);
        $this->assertEquals(310, $payload['revenue']['current']);
        $this->assertEquals(750, $payload['revenue']['previous']);
        $this->assertSame(2, $payload['sessions_paid']['current']);
        $this->assertSame(1, $payload['sessions_paid']['previous']);
    }

    public function test_non_admin_cannot_access_monthly_report(): void
    {
        $waiter = User::factory()->waiter()->create();

        $this->actingAs($waiter, 'sanctum')
            ->getJson('/api/reports/month')
            ->assertForbidden();
    }

    // ----- Waiter "me/today" -----

    public function test_me_today_returns_only_the_calling_waiters_numbers(): void
    {
        $alice = User::factory()->waiter()->create();
        $bob = User::factory()->waiter()->create();

        $this->paidSession($alice, $this->today->copy()->setTime(10, 0), [['chips', 2]]); // 300
        $this->paidSession($alice, $this->today->copy()->setTime(11, 0), [['soda', 1]]);  //  80
        $this->paidSession($bob, $this->today->copy()->setTime(12, 0), [['chips', 5]]);   // 750 -- not Alice's

        $payload = $this->actingAs($alice, 'sanctum')
            ->getJson('/api/me/today')
            ->assertOk()
            ->json();

        $this->assertSame(2, $payload['sessions_paid']);
        $this->assertEquals(380, $payload['revenue_collected']);
    }

    public function test_me_today_includes_sessions_with_items_total_and_method(): void
    {
        $waiter = User::factory()->waiter()->create();

        $this->paidSession(
            $waiter,
            $this->today->copy()->setTime(10, 0),
            [['chips', 2], ['soda', 1]],
            method: 'mpesa',
            mpesaCode: 'NLJ7RT61SV'
        );

        $payload = $this->actingAs($waiter, 'sanctum')
            ->getJson('/api/me/today')
            ->assertOk()
            ->json();

        $this->assertCount(1, $payload['sessions']);

        $session = $payload['sessions'][0];
        $this->assertEquals(380, $session['total']);
        $this->assertSame('mpesa', $session['method']);
        $this->assertSame('NLJ7RT61SV', $session['mpesa_code']);

        $itemNames = array_column($session['items'], 'name');
        $this->assertContains('Chips', $itemNames);
        $this->assertContains('Soda', $itemNames);
    }

    public function test_me_today_excludes_yesterdays_work(): void
    {
        $waiter = User::factory()->waiter()->create();

        $this->paidSession($waiter, $this->yesterday->copy()->setTime(13, 0), [['chips', 10]]);

        $payload = $this->actingAs($waiter, 'sanctum')
            ->getJson('/api/me/today')
            ->assertOk()
            ->json();

        $this->assertSame(0, $payload['sessions_paid']);
        $this->assertEquals(0, $payload['revenue_collected']);
    }
}
