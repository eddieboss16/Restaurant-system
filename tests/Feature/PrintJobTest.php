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

    // ----- Auto-queue on payment -----

    public function test_cash_payment_auto_queues_a_print_job(): void
    {
        $waiter = User::factory()->waiter()->create();
        $session = \App\Models\CustomerSession::create([
            'waiter_id' => $waiter->id,
            'status' => 'served',
            'opened_at' => now(),
        ]);
        \App\Models\Order::create([
            'session_id' => $session->id,
            'menu_item_id' => $this->menuItems['soda']->id,
            'quantity' => 1,
            'unit_price' => 80,
            'status' => 'delivered',
        ]);

        $this->actingAs($waiter, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/payment", ['method' => 'cash', 'amount' => 80])
            ->assertCreated();

        $this->assertDatabaseHas('print_jobs', [
            'session_id' => $session->id,
            'queued_by' => $waiter->id,
            'status' => 'pending',
        ]);
    }

    public function test_auto_queue_can_be_disabled_via_config(): void
    {
        config()->set('print.auto_queue', false);

        $waiter = User::factory()->waiter()->create();
        $session = \App\Models\CustomerSession::create([
            'waiter_id' => $waiter->id,
            'status' => 'served',
            'opened_at' => now(),
        ]);
        \App\Models\Order::create([
            'session_id' => $session->id,
            'menu_item_id' => $this->menuItems['soda']->id,
            'quantity' => 1,
            'unit_price' => 80,
            'status' => 'delivered',
        ]);

        $this->actingAs($waiter, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/payment", ['method' => 'cash', 'amount' => 80])
            ->assertCreated();

        $this->assertDatabaseCount('print_jobs', 0);
    }

    public function test_mpesa_callback_success_auto_queues_a_print_job(): void
    {
        $waiter = User::factory()->waiter()->create();
        $session = \App\Models\CustomerSession::create([
            'waiter_id' => $waiter->id,
            'status' => 'served',
            'opened_at' => now(),
        ]);
        \App\Models\Order::create([
            'session_id' => $session->id,
            'menu_item_id' => $this->menuItems['soda']->id,
            'quantity' => 1,
            'unit_price' => 80,
            'status' => 'delivered',
        ]);
        \App\Models\Payment::create([
            'session_id' => $session->id,
            'method' => 'mpesa',
            'amount' => 80,
            'status' => 'pending',
            'mpesa_checkout_request_id' => 'CR-auto',
            'collected_by' => $waiter->id,
        ]);

        $this->postJson('/api/mpesa/callback', [
            'Body' => ['stkCallback' => [
                'CheckoutRequestID' => 'CR-auto',
                'ResultCode' => 0,
                'ResultDesc' => 'Success',
                'CallbackMetadata' => ['Item' => [
                    ['Name' => 'MpesaReceiptNumber', 'Value' => 'NLJ7RT61SV'],
                ]],
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('print_jobs', [
            'session_id' => $session->id,
            'status' => 'pending',
        ]);
    }

    public function test_mpesa_callback_failure_does_not_queue_a_print_job(): void
    {
        $waiter = User::factory()->waiter()->create();
        $session = \App\Models\CustomerSession::create([
            'waiter_id' => $waiter->id,
            'status' => 'served',
            'opened_at' => now(),
        ]);
        \App\Models\Payment::create([
            'session_id' => $session->id,
            'method' => 'mpesa',
            'amount' => 80,
            'status' => 'pending',
            'mpesa_checkout_request_id' => 'CR-fail',
            'collected_by' => $waiter->id,
        ]);

        $this->postJson('/api/mpesa/callback', [
            'Body' => ['stkCallback' => [
                'CheckoutRequestID' => 'CR-fail',
                'ResultCode' => 1032,
                'ResultDesc' => 'User cancelled',
            ]],
        ])->assertOk();

        $this->assertDatabaseCount('print_jobs', 0);
    }

    // ----- Stuck-print sweep -----

    public function test_reset_stuck_print_jobs_pushes_old_printing_back_to_pending(): void
    {
        $waiter = User::factory()->waiter()->create();
        $session = $this->paidSession($waiter);

        $stuck = PrintJob::create([
            'session_id' => $session->id,
            'queued_by' => $waiter->id,
            'payload' => ['items' => []],
            'status' => 'printing',
        ]);
        $stuck->forceFill(['updated_at' => now()->subMinutes(5)])->save();

        $fresh = PrintJob::create([
            'session_id' => $session->id,
            'queued_by' => $waiter->id,
            'payload' => ['items' => []],
            'status' => 'printing',
        ]);
        $fresh->forceFill(['updated_at' => now()->subSeconds(30)])->save();

        $this->artisan('prints:reset-stuck')->assertSuccessful();

        $this->assertSame('pending', $stuck->fresh()->status);
        $this->assertSame('printing', $fresh->fresh()->status);
    }

    public function test_reset_stuck_print_jobs_does_not_touch_printed_or_failed(): void
    {
        $waiter = User::factory()->waiter()->create();
        $session = $this->paidSession($waiter);

        $printed = PrintJob::create([
            'session_id' => $session->id,
            'queued_by' => $waiter->id,
            'payload' => ['items' => []],
            'status' => 'printed',
        ]);
        $printed->forceFill(['updated_at' => now()->subHour()])->save();

        $failed = PrintJob::create([
            'session_id' => $session->id,
            'queued_by' => $waiter->id,
            'payload' => ['items' => []],
            'status' => 'failed',
            'error' => 'something',
        ]);
        $failed->forceFill(['updated_at' => now()->subHour()])->save();

        $this->artisan('prints:reset-stuck')->assertSuccessful();

        $this->assertSame('printed', $printed->fresh()->status);
        $this->assertSame('failed', $failed->fresh()->status);
    }
}
