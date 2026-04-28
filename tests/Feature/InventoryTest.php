<?php

namespace Tests\Feature;

use App\Models\CustomerSession;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsRestaurantData;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;
    use SeedsRestaurantData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRestaurantData();
    }

    public function test_creating_chips_order_deducts_potatoes_and_oil(): void
    {
        $waiter = User::factory()->waiter()->create();
        $session = CustomerSession::create([
            'waiter_id' => $waiter->id,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $this->actingAs($waiter, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/orders", [
                'items' => [
                    ['menu_item_id' => $this->menuItems['chips']->id, 'quantity' => 2],
                ],
            ])->assertCreated();

        $this->assertSame(9400.0, (float) $this->resources['potatoes']->fresh()->current_stock);
        $this->assertSame(4880.0, (float) $this->resources['oil']->fresh()->current_stock);

        $this->assertDatabaseHas('resource_transactions', [
            'resource_id' => $this->resources['potatoes']->id,
            'change_amount' => -600.000,
            'type' => 'auto_deduction',
        ]);
    }

    public function test_drink_orders_do_not_deduct_inventory(): void
    {
        $waiter = User::factory()->waiter()->create();
        $session = CustomerSession::create([
            'waiter_id' => $waiter->id,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $this->actingAs($waiter, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/orders", [
                'items' => [
                    ['menu_item_id' => $this->menuItems['soda']->id, 'quantity' => 5],
                ],
            ])->assertCreated();

        $this->assertSame(10000.0, (float) $this->resources['potatoes']->fresh()->current_stock);
        $this->assertSame(5000.0, (float) $this->resources['oil']->fresh()->current_stock);
    }

    public function test_order_blocked_when_an_ingredient_is_short(): void
    {
        $waiter = User::factory()->waiter()->create();
        $session = CustomerSession::create([
            'waiter_id' => $waiter->id,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        // Chips needs 300g potatoes per unit; 100 × 300 = 30000, only 10000 in stock.
        $response = $this->actingAs($waiter, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/orders", [
                'items' => [
                    ['menu_item_id' => $this->menuItems['chips']->id, 'quantity' => 100],
                ],
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('potatoes', $response->json('message'));

        // Nothing persisted: no order, no transaction, stock unchanged.
        $this->assertDatabaseCount(Order::class, 0);
        $this->assertDatabaseCount('resource_transactions', 0);
        $this->assertSame(10000.0, (float) $this->resources['potatoes']->fresh()->current_stock);
        $this->assertSame(5000.0, (float) $this->resources['oil']->fresh()->current_stock);
    }

    public function test_low_stock_endpoint_returns_only_resources_at_or_below_threshold(): void
    {
        // potatoes seeded with 10000g, threshold 2000 -- not low.
        // oil seeded with 5000ml, threshold 1000 -- not low.
        $this->resources['potatoes']->update(['current_stock' => 1500]); // now low
        $this->resources['oil']->update(['current_stock' => 1000]);      // exactly at threshold = low

        $manager = User::factory()->manager()->create();

        $payload = $this->actingAs($manager, 'sanctum')
            ->getJson('/api/inventory/low-stock')
            ->assertOk()
            ->json();

        $names = array_column($payload, 'name');
        $this->assertContains('potatoes', $names);
        $this->assertContains('oil', $names);
        $this->assertCount(2, $payload);
    }

    public function test_low_stock_endpoint_blocked_for_waiter_and_kitchen(): void
    {
        $waiter = User::factory()->waiter()->create();
        $kitchen = User::factory()->kitchen()->create();

        $this->actingAs($waiter, 'sanctum')->getJson('/api/inventory/low-stock')->assertForbidden();
        $this->actingAs($kitchen, 'sanctum')->getJson('/api/inventory/low-stock')->assertForbidden();
    }

    public function test_partial_failure_in_batch_rolls_back_all_orders_and_deductions(): void
    {
        $waiter = User::factory()->waiter()->create();
        $session = CustomerSession::create([
            'waiter_id' => $waiter->id,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        // Soda first (would succeed), then Chips × 100 (will fail). The whole batch
        // should roll back -- the soda order must NOT exist after the request.
        $this->actingAs($waiter, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/orders", [
                'items' => [
                    ['menu_item_id' => $this->menuItems['soda']->id, 'quantity' => 1],
                    ['menu_item_id' => $this->menuItems['chips']->id, 'quantity' => 100],
                ],
            ])->assertStatus(422);

        $this->assertDatabaseCount(Order::class, 0);
        $this->assertDatabaseCount('resource_transactions', 0);
        $this->assertSame('open', $session->fresh()->status);
    }
}
