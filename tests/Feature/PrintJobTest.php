<?php

namespace Tests\Feature;

use App\Models\CustomerSession;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PrintJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsRestaurantData;
use Tests\TestCase;

class PrintJobTest extends TestCase
{
    use RefreshDatabase;
    use SeedsRestaurantData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRestaurantData();
        config()->set('print.bridge_token', 'test-bridge-secret');
    }

    private function paidSession(User $waiter): CustomerSession
    {
        $session = CustomerSession::create([
            'waiter_id' => $waiter->id,
            'customer_label' => 'lady in red',
            'status' => 'paid',
            'opened_at' => now()->subHour(),
            'closed_at' => now(),
        ]);
        Order::create([
            'session_id' => $session->id,
            'menu_item_id' => $this->menuItems['soda']->id,
            'quantity' => 2,
            'unit_price' => 80,
            'status' => 'delivered',
        ]);
        Payment::create([
            'session_id' => $session->id,
            'method' => 'cash',
            'amount' => 160,
            'status' => 'completed',
            'collected_by' => $waiter->id,
            'confirmed_at' => now(),
        ]);
        return $session;
    }

    // ----- Queueing (waiter side) -----

    public function test_waiter_can_queue_a_receipt_print_with_a_payload(): void
    {
        $waiter = User::factory()->waiter()->create();
        $session = $this->paidSession($waiter);

        $response = $this->actingAs($waiter, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/receipt")
            ->assertStatus(202);

        $this->assertDatabaseHas('print_jobs', [
            'session_id' => $session->id,
            'queued_by' => $waiter->id,
            'status' => 'pending',
        ]);

        $payload = $response->json('payload');
        $this->assertSame('lady in red', $payload['meta']['customer_label']);
        $this->assertCount(1, $payload['items']);
        $this->assertEquals(160, $payload['totals']['total']);
        $this->assertSame('cash', $payload['payment']['method']);
    }

    public function test_other_waiter_cannot_queue_a_receipt_for_someone_elses_session(): void
    {
        $owner = User::factory()->waiter()->create();
        $other = User::factory()->waiter()->create();
        $session = $this->paidSession($owner);

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/receipt")
            ->assertForbidden();
    }

    // ----- Bridge endpoints (token-based) -----

    public function test_bridge_endpoints_reject_missing_or_wrong_token(): void
    {
        $this->getJson('/api/print-jobs/pending')->assertUnauthorized();
        $this->withHeader('X-Bridge-Token', 'wrong')
            ->getJson('/api/print-jobs/pending')
            ->assertUnauthorized();
    }

    public function test_bridge_pending_returns_oldest_pending_and_flips_to_printing(): void
    {
        $waiter = User::factory()->waiter()->create();
        $session = $this->paidSession($waiter);

        $job = PrintJob::create([
            'session_id' => $session->id,
            'queued_by' => $waiter->id,
            'payload' => ['items' => []],
            'status' => 'pending',
        ]);

        $response = $this->withHeader('X-Bridge-Token', 'test-bridge-secret')
            ->getJson('/api/print-jobs/pending')
            ->assertOk()
            ->json();

        $this->assertSame($job->id, $response['id']);
        $this->assertSame('printing', $response['status']);
        $this->assertSame('printing', $job->fresh()->status);

        // Second poll should not return the same job again -- 204 No Content.
        $this->withHeader('X-Bridge-Token', 'test-bridge-secret')
            ->getJson('/api/print-jobs/pending')
            ->assertNoContent();
    }

    public function test_bridge_can_ack_a_printed_job(): void
    {
        $waiter = User::factory()->waiter()->create();
        $session = $this->paidSession($waiter);
        $job = PrintJob::create([
            'session_id' => $session->id,
            'queued_by' => $waiter->id,
            'payload' => ['items' => []],
            'status' => 'printing',
        ]);

        $this->withHeader('X-Bridge-Token', 'test-bridge-secret')
            ->postJson("/api/print-jobs/{$job->id}/ack")
            ->assertOk();

        $job->refresh();
        $this->assertSame('printed', $job->status);
        $this->assertNotNull($job->printed_at);
    }

    public function test_bridge_can_report_a_failure_with_error_message(): void
    {
        $waiter = User::factory()->waiter()->create();
        $session = $this->paidSession($waiter);
        $job = PrintJob::create([
            'session_id' => $session->id,
            'queued_by' => $waiter->id,
            'payload' => ['items' => []],
            'status' => 'printing',
        ]);

        $this->withHeader('X-Bridge-Token', 'test-bridge-secret')
            ->postJson("/api/print-jobs/{$job->id}/fail", ['error' => 'printer offline'])
            ->assertOk();

        $job->refresh();
        $this->assertSame('failed', $job->status);
        $this->assertSame('printer offline', $job->error);
    }
}
