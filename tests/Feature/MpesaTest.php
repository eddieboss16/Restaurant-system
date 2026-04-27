<?php

namespace Tests\Feature;

use App\Models\CustomerSession;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\MpesaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SeedsRestaurantData;
use Tests\TestCase;

class MpesaTest extends TestCase
{
    use RefreshDatabase;
    use SeedsRestaurantData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRestaurantData();

        config()->set('mpesa.consumer_key', 'test_key');
        config()->set('mpesa.consumer_secret', 'test_secret');
        config()->set('mpesa.callback_url', 'https://restaurant.test/api/mpesa/callback');
        config()->set('mpesa.shortcode', '174379');
        config()->set('mpesa.base_url', 'https://sandbox.safaricom.co.ke');

        Cache::forget('mpesa.access_token');
    }

    // ----- MpesaService unit-ish tests -----

    public function test_normalize_phone_handles_common_kenyan_formats(): void
    {
        $service = app(MpesaService::class);

        $this->assertSame('254712345678', $service->normalizePhone('0712345678'));
        $this->assertSame('254712345678', $service->normalizePhone('712345678'));
        $this->assertSame('254712345678', $service->normalizePhone('+254 712 345 678'));
        $this->assertSame('254712345678', $service->normalizePhone('254712345678'));
    }

    public function test_initiate_stk_push_sends_correctly_shaped_request_to_daraja(): void
    {
        Http::fake([
            'sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response(['access_token' => 'mock-token'], 200),
            'sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest' => Http::response([
                'MerchantRequestID' => 'MR-1',
                'CheckoutRequestID' => 'CR-1',
                'ResponseCode' => '0',
                'ResponseDescription' => 'Success',
                'CustomerMessage' => 'Success',
            ], 200),
        ]);

        $service = app(MpesaService::class);

        $result = $service->initiateStkPush('0712345678', 250.0, 'SESSION1', 'Tab');

        $this->assertSame('MR-1', $result['merchant_request_id']);
        $this->assertSame('CR-1', $result['checkout_request_id']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'stkpush')) return false;
            $body = $request->data();
            return $body['BusinessShortCode'] === '174379'
                && $body['Amount'] === 250
                && $body['PhoneNumber'] === '254712345678'
                && $body['PartyA'] === '254712345678'
                && $body['PartyB'] === '174379'
                && $body['CallBackURL'] === 'https://restaurant.test/api/mpesa/callback'
                && ! empty($body['Password'])
                && ! empty($body['Timestamp']);
        });
    }

    public function test_initiate_stk_throws_when_credentials_are_missing(): void
    {
        config()->set('mpesa.consumer_key', null);
        config()->set('mpesa.consumer_secret', null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('credentials are missing');

        app(MpesaService::class)->initiateStkPush('0712345678', 100.0, 'X', 'Y');
    }

    public function test_initiate_stk_throws_when_callback_url_is_missing(): void
    {
        config()->set('mpesa.callback_url', null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('callback URL is not configured');

        app(MpesaService::class)->initiateStkPush('0712345678', 100.0, 'X', 'Y');
    }

    public function test_initiate_stk_surfaces_daraja_error_response(): void
    {
        Http::fake([
            'sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response(['access_token' => 'mock-token'], 200),
            'sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest' => Http::response([
                'errorCode' => '500.001.1001',
                'errorMessage' => 'Wrong credentials',
            ], 400),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Wrong credentials');

        app(MpesaService::class)->initiateStkPush('0712345678', 100.0, 'X', 'Y');
    }

    // ----- Initiate-STK endpoint tests -----

    public function test_initiate_stk_endpoint_creates_pending_payment_and_returns_202(): void
    {
        Http::fake([
            'sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response(['access_token' => 'mock-token'], 200),
            'sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest' => Http::response([
                'MerchantRequestID' => 'MR-2',
                'CheckoutRequestID' => 'CR-2',
                'ResponseCode' => '0',
                'ResponseDescription' => 'Success',
                'CustomerMessage' => 'Success',
            ], 200),
        ]);

        $waiter = User::factory()->waiter()->create();
        $session = $this->sessionWithDeliveredOrder($waiter);

        $this->actingAs($waiter, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/payment/stk", [
                'phone' => '0712345678',
                'amount' => 80,
            ])->assertStatus(202)
              ->assertJsonFragment([
                  'status' => 'pending',
                  'mpesa_checkout_request_id' => 'CR-2',
              ]);

        $this->assertDatabaseHas('payments', [
            'session_id' => $session->id,
            'status' => 'pending',
            'phone_number' => '254712345678',
            'mpesa_checkout_request_id' => 'CR-2',
        ]);
    }

    public function test_initiate_stk_blocked_when_orders_still_pending(): void
    {
        $waiter = User::factory()->waiter()->create();
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
            'status' => 'pending',
        ]);

        $this->actingAs($waiter, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/payment/stk", [
                'phone' => '0712345678',
                'amount' => 80,
            ])->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_initiate_stk_blocked_if_an_stk_is_already_pending(): void
    {
        $waiter = User::factory()->waiter()->create();
        $session = $this->sessionWithDeliveredOrder($waiter);
        Payment::create([
            'session_id' => $session->id,
            'method' => 'mpesa',
            'amount' => 80,
            'status' => 'pending',
            'mpesa_checkout_request_id' => 'CR-existing',
            'collected_by' => $waiter->id,
        ]);

        $this->actingAs($waiter, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/payment/stk", [
                'phone' => '0712345678',
                'amount' => 80,
            ])->assertStatus(422);
    }

    public function test_initiate_stk_blocked_if_session_already_paid(): void
    {
        $waiter = User::factory()->waiter()->create();
        $session = $this->sessionWithDeliveredOrder($waiter);
        Payment::create([
            'session_id' => $session->id,
            'method' => 'mpesa',
            'amount' => 80,
            'status' => 'completed',
            'mpesa_code' => 'OLDCODE',
            'collected_by' => $waiter->id,
            'confirmed_at' => now(),
        ]);

        $this->actingAs($waiter, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/payment/stk", [
                'phone' => '0712345678',
                'amount' => 80,
            ])->assertStatus(422);
    }

    public function test_initiate_stk_records_failure_and_returns_502_on_daraja_error(): void
    {
        Http::fake([
            'sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response(['access_token' => 'mock-token'], 200),
            'sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest' => Http::response([
                'errorCode' => '500.001.1001',
                'errorMessage' => 'Service down',
            ], 500),
        ]);

        $waiter = User::factory()->waiter()->create();
        $session = $this->sessionWithDeliveredOrder($waiter);

        $this->actingAs($waiter, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/payment/stk", [
                'phone' => '0712345678',
                'amount' => 80,
            ])->assertStatus(502);

        $this->assertDatabaseHas('payments', [
            'session_id' => $session->id,
            'status' => 'failed',
        ]);
    }

    // ----- Callback handling -----

    public function test_callback_with_success_marks_payment_completed_and_closes_session(): void
    {
        $waiter = User::factory()->waiter()->create();
        $session = $this->sessionWithDeliveredOrder($waiter);
        $payment = Payment::create([
            'session_id' => $session->id,
            'method' => 'mpesa',
            'amount' => 80,
            'status' => 'pending',
            'mpesa_checkout_request_id' => 'CR-success',
            'mpesa_merchant_request_id' => 'MR-success',
            'collected_by' => $waiter->id,
        ]);

        $response = $this->postJson('/api/mpesa/callback', [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'MR-success',
                    'CheckoutRequestID' => 'CR-success',
                    'ResultCode' => 0,
                    'ResultDesc' => 'Success',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 80],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'NLJ7RT61SV'],
                            ['Name' => 'TransactionDate', 'Value' => 20260427101500],
                            ['Name' => 'PhoneNumber', 'Value' => 254712345678],
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertOk()->assertJson(['ResultCode' => 0]);

        $payment->refresh();
        $this->assertSame('completed', $payment->status);
        $this->assertSame('NLJ7RT61SV', $payment->mpesa_code);
        $this->assertSame(0, $payment->mpesa_result_code);
        $this->assertNotNull($payment->confirmed_at);

        $this->assertSame('paid', $session->fresh()->status);
        $this->assertNotNull($session->fresh()->closed_at);
    }

    public function test_callback_with_failure_marks_payment_failed_and_leaves_session_open(): void
    {
        $waiter = User::factory()->waiter()->create();
        $session = $this->sessionWithDeliveredOrder($waiter);
        Payment::create([
            'session_id' => $session->id,
            'method' => 'mpesa',
            'amount' => 80,
            'status' => 'pending',
            'mpesa_checkout_request_id' => 'CR-fail',
            'collected_by' => $waiter->id,
        ]);

        $this->postJson('/api/mpesa/callback', [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'MR-fail',
                    'CheckoutRequestID' => 'CR-fail',
                    'ResultCode' => 1032,
                    'ResultDesc' => 'Request cancelled by user',
                ],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('payments', [
            'session_id' => $session->id,
            'status' => 'failed',
            'mpesa_result_code' => 1032,
        ]);

        $this->assertNotSame('paid', $session->fresh()->status);
    }

    public function test_callback_for_unknown_checkout_id_responds_ok(): void
    {
        $this->postJson('/api/mpesa/callback', [
            'Body' => [
                'stkCallback' => [
                    'CheckoutRequestID' => 'CR-does-not-exist',
                    'ResultCode' => 0,
                    'ResultDesc' => 'Success',
                ],
            ],
        ])->assertOk()->assertJson(['ResultCode' => 0]);
    }

    public function test_callback_is_idempotent(): void
    {
        $waiter = User::factory()->waiter()->create();
        $session = $this->sessionWithDeliveredOrder($waiter);
        $payment = Payment::create([
            'session_id' => $session->id,
            'method' => 'mpesa',
            'amount' => 80,
            'status' => 'completed',
            'mpesa_code' => 'ORIGINAL',
            'mpesa_checkout_request_id' => 'CR-dup',
            'collected_by' => $waiter->id,
            'confirmed_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/mpesa/callback', [
            'Body' => [
                'stkCallback' => [
                    'CheckoutRequestID' => 'CR-dup',
                    'ResultCode' => 0,
                    'ResultDesc' => 'Success',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'OVERWRITE_ATTEMPT'],
                        ],
                    ],
                ],
            ],
        ])->assertOk();

        // mpesa_code unchanged -- the second callback is ignored.
        $this->assertSame('ORIGINAL', $payment->fresh()->mpesa_code);
    }

    public function test_callback_route_does_not_require_auth(): void
    {
        // Just confirm the route is reachable without a Sanctum token.
        $this->postJson('/api/mpesa/callback', ['Body' => []])
            ->assertOk();
    }

    // ----- Helpers -----

    private function sessionWithDeliveredOrder(User $waiter): CustomerSession
    {
        $session = CustomerSession::create([
            'waiter_id' => $waiter->id,
            'status' => 'served',
            'opened_at' => now(),
        ]);
        Order::create([
            'session_id' => $session->id,
            'menu_item_id' => $this->menuItems['soda']->id,
            'quantity' => 1,
            'unit_price' => 80,
            'status' => 'delivered',
        ]);
        return $session;
    }
}
