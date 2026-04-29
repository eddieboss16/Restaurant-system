<?php

namespace Tests\Feature;

use App\Models\CancellationLog;
use App\Models\CustomerSession;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsRestaurantData;
use Tests\TestCase;

class OrderFlowTest extends TestCase
{
    use RefreshDatabase;
    use SeedsRestaurantData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRestaurantData();
    }

    public function test_waiter_opens_session_and_session_starts_open(): void
    {
        $waiter = User::factory()->waiter()->create();

        $response = $this->actingAs($waiter, 'sanctum')
            ->postJson('/api/sessions', ['customer_label' => 'lady in red']);

        $response->assertCreated()
            ->assertJsonFragment([
                'waiter_id' => $waiter->id,
                'customer_label' => 'lady in red',
                'status' => 'open',
            ]);
    }

    public function test_adding_first_order_flips_session_status_to_ordered(): void
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
                    ['menu_item_id' => $this->menuItems['soda']->id, 'quantity' => 1],
                ],
            ])->assertCreated();

        $this->assertSame('ordered', $session->fresh()->status);
    }

    public function test_only_assigned_waiter_can_add_orders_to_their_session(): void
    {
        $owner = User::factory()->waiter()->create();
        $other = User::factory()->waiter()->create();
        $session = CustomerSession::create([
            'waiter_id' => $owner->id,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/orders", [
                'items' => [
                    ['menu_item_id' => $this->menuItems['soda']->id, 'quantity' => 1],
                ],
            ])->assertForbidden();
    }

    public function test_manager_can_add_orders_to_any_session(): void
    {
        $waiter = User::factory()->waiter()->create();
        $manager = User::factory()->manager()->create();
        $session = CustomerSession::create([
            'waiter_id' => $waiter->id,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/orders", [
                'items' => [
                    ['menu_item_id' => $this->menuItems['soda']->id, 'quantity' => 1],
                ],
            ])->assertCreated();
    }

    public function test_kitchen_can_mark_preparing_and_ready_but_not_delivered(): void
    {
        $waiter = User::factory()->waiter()->create();
        $kitchen = User::factory()->kitchen()->create();
        $session = CustomerSession::create([
            'waiter_id' => $waiter->id,
            'status' => 'ordered',
            'opened_at' => now(),
        ]);
        $order = Order::create([
            'session_id' => $session->id,
            'menu_item_id' => $this->menuItems['soda']->id,
            'quantity' => 1,
            'unit_price' => 80,
            'status' => 'pending',
        ]);

        $this->actingAs($kitchen, 'sanctum')
            ->patchJson("/api/orders/{$order->id}/status", ['status' => 'preparing'])
            ->assertOk();
        $this->assertSame('preparing', $order->fresh()->status);

        $this->actingAs($kitchen, 'sanctum')
            ->patchJson("/api/orders/{$order->id}/status", ['status' => 'ready'])
            ->assertOk();

        $this->actingAs($kitchen, 'sanctum')
            ->patchJson("/api/orders/{$order->id}/status", ['status' => 'delivered'])
            ->assertForbidden();
    }

    public function test_waiter_marks_delivered_and_session_flips_to_served_when_all_done(): void
    {
        $waiter = User::factory()->waiter()->create();
        $session = CustomerSession::create([
            'waiter_id' => $waiter->id,
            'status' => 'ordered',
            'opened_at' => now(),
        ]);
        $order = Order::create([
            'session_id' => $session->id,
            'menu_item_id' => $this->menuItems['soda']->id,
            'quantity' => 1,
            'unit_price' => 80,
            'status' => 'ready',
        ]);

        $this->actingAs($waiter, 'sanctum')
            ->patchJson("/api/orders/{$order->id}/status", ['status' => 'delivered'])
            ->assertOk();

        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertSame('served', $session->fresh()->status);
    }

    public function test_cancellation_logs_reason_and_blocks_after_delivery(): void
    {
        $waiter = User::factory()->waiter()->create();
        $session = CustomerSession::create([
            'waiter_id' => $waiter->id,
            'status' => 'ordered',
            'opened_at' => now(),
        ]);
        $order = Order::create([
            'session_id' => $session->id,
            'menu_item_id' => $this->menuItems['soda']->id,
            'quantity' => 1,
            'unit_price' => 80,
            'status' => 'pending',
        ]);

        $this->actingAs($waiter, 'sanctum')
            ->deleteJson("/api/orders/{$order->id}", ['reason' => 'wrong order'])
            ->assertOk();

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertDatabaseHas('cancellation_logs', [
            'order_id' => $order->id,
            'cancelled_by' => $waiter->id,
            'reason' => 'wrong order',
        ]);

        $delivered = Order::create([
            'session_id' => $session->id,
            'menu_item_id' => $this->menuItems['soda']->id,
            'quantity' => 1,
            'unit_price' => 80,
            'status' => 'delivered',
        ]);

        $this->actingAs($waiter, 'sanctum')
            ->deleteJson("/api/orders/{$delivered->id}", ['reason' => 'too late'])
            ->assertStatus(422);
    }

    public function test_paid_sessions_history_returns_only_paid_sessions_for_manager(): void
    {
        $waiter = User::factory()->waiter()->create();
        $manager = User::factory()->manager()->create();

        $paid = CustomerSession::create([
            'waiter_id' => $waiter->id,
            'customer_label' => 'lady in red',
            'status' => 'paid',
            'opened_at' => now()->subHour(),
            'closed_at' => now()->subMinutes(5),
        ]);
        \App\Models\Payment::create([
            'session_id' => $paid->id,
            'method' => 'cash',
            'amount' => 80,
            'status' => 'completed',
            'collected_by' => $waiter->id,
            'confirmed_at' => now()->subMinutes(5),
        ]);
        Order::create([
            'session_id' => $paid->id,
            'menu_item_id' => $this->menuItems['soda']->id,
            'quantity' => 1,
            'unit_price' => 80,
            'status' => 'delivered',
        ]);

        // An open session that should NOT appear.
        CustomerSession::create([
            'waiter_id' => $waiter->id,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $payload = $this->actingAs($manager, 'sanctum')
            ->getJson('/api/paid-sessions')
            ->assertOk()
            ->json();

        $this->assertCount(1, $payload);
        $this->assertSame($paid->id, $payload[0]['id']);
        $this->assertSame('lady in red', $payload[0]['customer_label']);
        $this->assertSame('cash', $payload[0]['payment']['method']);
    }

    public function test_paid_sessions_blocked_for_waiter_and_kitchen(): void
    {
        $this->actingAs(User::factory()->waiter()->create(), 'sanctum')
            ->getJson('/api/paid-sessions')->assertForbidden();
        $this->actingAs(User::factory()->kitchen()->create(), 'sanctum')
            ->getJson('/api/paid-sessions')->assertForbidden();
    }

    public function test_kitchen_history_returns_recent_delivered_orders_only(): void
    {
        $waiter = User::factory()->waiter()->create();
        $kitchen = User::factory()->kitchen()->create();

        $session = CustomerSession::create([
            'waiter_id' => $waiter->id,
            'status' => 'served',
            'opened_at' => now(),
        ]);

        $delivered = Order::create([
            'session_id' => $session->id,
            'menu_item_id' => $this->menuItems['soda']->id,
            'quantity' => 2,
            'unit_price' => 80,
            'status' => 'delivered',
        ]);
        Order::create([
            'session_id' => $session->id,
            'menu_item_id' => $this->menuItems['soda']->id,
            'quantity' => 1,
            'unit_price' => 80,
            'status' => 'pending',
        ]);
        Order::create([
            'session_id' => $session->id,
            'menu_item_id' => $this->menuItems['soda']->id,
            'quantity' => 1,
            'unit_price' => 80,
            'status' => 'cancelled',
        ]);

        $payload = $this->actingAs($kitchen, 'sanctum')
            ->getJson('/api/kitchen/history')
            ->assertOk()
            ->json();

        $this->assertCount(1, $payload);
        $this->assertSame($delivered->id, $payload[0]['id']);
    }

    public function test_kitchen_history_blocked_for_waiter_role(): void
    {
        $waiter = User::factory()->waiter()->create();

        $this->actingAs($waiter, 'sanctum')
            ->getJson('/api/kitchen/history')
            ->assertForbidden();
    }

    public function test_cancellation_reason_must_be_at_least_5_chars(): void
    {
        $waiter = User::factory()->waiter()->create();
        $session = CustomerSession::create([
            'waiter_id' => $waiter->id,
            'status' => 'ordered',
            'opened_at' => now(),
        ]);
        $order = Order::create([
            'session_id' => $session->id,
            'menu_item_id' => $this->menuItems['soda']->id,
            'quantity' => 1,
            'unit_price' => 80,
            'status' => 'pending',
        ]);

        $this->actingAs($waiter, 'sanctum')
            ->deleteJson("/api/orders/{$order->id}", ['reason' => 'oops'])
            ->assertStatus(422);

        $this->assertDatabaseCount(CancellationLog::class, 0);
    }
}
