<?php

namespace Tests\Feature;

use App\Models\CustomerSession;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsRestaurantData;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;
    use SeedsRestaurantData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRestaurantData();
    }

    private function sessionWithOrder(User $waiter, string $orderStatus): CustomerSession
    {
        $session = CustomerSession::create([
            'waiter_id' => $waiter->id,
            'status' => 'ordered',
            'opened_at' => now(),
        ]);
        Order::create([
            'session_id' => $session->id,
            'menu_item_id' => $this->menuItems['soda']->id,
            'quantity' => 1,
            'unit_price' => 80,
            'status' => $orderStatus,
        ]);
        return $session;
    }

    public function test_cash_payment_succeeds_when_all_orders_delivered(): void
    {
        $waiter = User::factory()->waiter()->create();
        $session = $this->sessionWithOrder($waiter, 'delivered');

        $this->actingAs($waiter, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/payment", [
                'method' => 'cash',
                'amount' => 80,
            ])->assertCreated();

        $this->assertSame('paid', $session->fresh()->status);
        $this->assertNotNull($session->fresh()->closed_at);
    }

    public function test_payment_blocked_while_orders_are_pending(): void
    {
        $waiter = User::factory()->waiter()->create();
        $session = $this->sessionWithOrder($waiter, 'pending');

        $this->actingAs($waiter, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/payment", [
                'method' => 'cash',
                'amount' => 80,
            ])->assertStatus(422);

        $this->assertSame('ordered', $session->fresh()->status);
    }

    public function test_payment_blocked_while_orders_are_preparing(): void
    {
        $waiter = User::factory()->waiter()->create();
        $session = $this->sessionWithOrder($waiter, 'preparing');

        $this->actingAs($waiter, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/payment", [
                'method' => 'cash',
                'amount' => 80,
            ])->assertStatus(422);
    }

    public function test_mpesa_payment_requires_transaction_code(): void
    {
        $waiter = User::factory()->waiter()->create();
        $session = $this->sessionWithOrder($waiter, 'delivered');

        $this->actingAs($waiter, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/payment", [
                'method' => 'mpesa',
                'amount' => 80,
                'mpesa_code' => null,
            ])->assertStatus(422);
    }

    public function test_mpesa_payment_succeeds_with_code(): void
    {
        $waiter = User::factory()->waiter()->create();
        $session = $this->sessionWithOrder($waiter, 'delivered');

        $this->actingAs($waiter, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/payment", [
                'method' => 'mpesa',
                'amount' => 80,
                'mpesa_code' => 'QJK12ABCD3',
            ])->assertCreated();

        $this->assertDatabaseHas('payments', [
            'session_id' => $session->id,
            'method' => 'mpesa',
            'mpesa_code' => 'QJK12ABCD3',
        ]);
    }

    public function test_other_waiter_cannot_collect_payment_on_someone_elses_session(): void
    {
        $owner = User::factory()->waiter()->create();
        $other = User::factory()->waiter()->create();
        $session = $this->sessionWithOrder($owner, 'delivered');

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/payment", [
                'method' => 'cash',
                'amount' => 80,
            ])->assertForbidden();
    }
}
