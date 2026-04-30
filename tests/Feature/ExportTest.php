<?php

namespace Tests\Feature;

use App\Models\CustomerSession;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SeedsRestaurantData;
use Tests\TestCase;

class ExportTest extends TestCase
{
    use RefreshDatabase;
    use SeedsRestaurantData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRestaurantData();
        Carbon::setTestNow(Carbon::create(2026, 4, 30, 12, 0, 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function paidSession(User $waiter, string $closedAt, array $items, string $method = 'cash', ?string $mpesaCode = null): CustomerSession
    {
        $closed = Carbon::parse($closedAt);
        $session = CustomerSession::create([
            'waiter_id' => $waiter->id,
            'customer_label' => 'lady in red',
            'status' => 'paid',
            'opened_at' => $closed->copy()->subHour(),
            'closed_at' => $closed,
        ]);
        $total = 0.0;
        foreach ($items as [$key, $qty]) {
            $price = (float) $this->menuItems[$key]->price;
            Order::create([
                'session_id' => $session->id,
                'menu_item_id' => $this->menuItems[$key]->id,
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
            'confirmed_at' => $closed,
        ]);
        return $session;
    }

    public function test_paid_sessions_csv_includes_header_row_and_in_range_rows_only(): void
    {
        $admin = User::factory()->admin()->create();
        $waiter = User::factory()->waiter()->create(['name' => 'Amina']);

        $this->paidSession($waiter, '2026-04-30 10:00', [['chips', 2]], 'cash');
        $this->paidSession($waiter, '2026-04-30 14:00', [['soda', 3]], 'mpesa', 'NLJ7RT61SV');
        // Out of range:
        $this->paidSession($waiter, '2026-03-15 12:00', [['chips', 100]], 'cash');

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/exports/paid-sessions.csv?from=2026-04-01&to=2026-04-30')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($csv))));

        $this->assertCount(3, $lines, 'header + 2 in-range rows');
        $this->assertStringStartsWith('session_id,closed_at,customer_label,waiter,items,total_kes,method,mpesa_code', $lines[0]);
        $this->assertStringContainsString('Amina', $lines[1]);
        $this->assertStringContainsString('cash', $lines[1]);
        $this->assertStringContainsString('NLJ7RT61SV', $lines[2]);
    }

    public function test_expenses_csv_includes_header_row_and_in_range_rows_only(): void
    {
        $manager = User::factory()->manager()->create(['name' => 'Mgr']);

        Expense::create([
            'amount' => 1500, 'category' => 'supplies',
            'description' => 'Cooking gas', 'incurred_on' => '2026-04-29',
            'recorded_by' => $manager->id,
        ]);
        Expense::create([
            'amount' => 500, 'category' => 'transport',
            'description' => 'Matatu', 'incurred_on' => '2026-04-30',
            'recorded_by' => $manager->id,
        ]);
        // Out of range:
        Expense::create([
            'amount' => 9999, 'category' => 'rent',
            'description' => 'March rent', 'incurred_on' => '2026-03-01',
            'recorded_by' => $manager->id,
        ]);

        $response = $this->actingAs($manager, 'sanctum')
            ->get('/api/exports/expenses.csv?from=2026-04-01&to=2026-04-30')
            ->assertOk();

        $csv = $response->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($csv))));

        $this->assertCount(3, $lines, 'header + 2 in-range rows');
        $this->assertStringStartsWith('id,incurred_on,category,description,amount_kes,recorded_by', $lines[0]);
        $this->assertStringContainsString('Cooking gas', $lines[1]);
        $this->assertStringContainsString('Matatu', $lines[2]);
        $this->assertStringNotContainsString('March rent', $csv);
    }

    public function test_csv_exports_blocked_for_waiter_and_kitchen(): void
    {
        foreach ([User::factory()->waiter()->create(), User::factory()->kitchen()->create()] as $u) {
            $this->actingAs($u, 'sanctum')->get('/api/exports/paid-sessions.csv')->assertForbidden();
            $this->actingAs($u, 'sanctum')->get('/api/exports/expenses.csv')->assertForbidden();
        }
    }
}
