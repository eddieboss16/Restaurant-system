<?php

namespace Tests\Feature;

use App\Models\CustomerSession;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\WhatsappService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SeedsRestaurantData;
use Tests\TestCase;

class WhatsappTest extends TestCase
{
    use RefreshDatabase;
    use SeedsRestaurantData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRestaurantData();

        Carbon::setTestNow(Carbon::create(2026, 4, 29, 23, 59, 0));

        config()->set('whatsapp.enabled', true);
        config()->set('whatsapp.access_token', 'test_token');
        config()->set('whatsapp.phone_number_id', '123456789');
        config()->set('whatsapp.graph_version', 'v20.0');
        config()->set('whatsapp.owner_recipient', '254712345678');
        config()->set('whatsapp.daily_summary_template', null); // text mode by default
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function paidSession(User $waiter, string $time, array $items, string $method = 'cash'): void
    {
        $closedAt = Carbon::parse($time);
        $session = CustomerSession::create([
            'waiter_id' => $waiter->id,
            'status' => 'paid',
            'opened_at' => $closedAt->copy()->subHour(),
            'closed_at' => $closedAt,
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
            'collected_by' => $waiter->id,
            'confirmed_at' => $closedAt,
        ]);
    }

    // ----- Service unit-ish -----

    public function test_send_text_posts_to_meta_graph_endpoint_with_token(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.HBg=']]], 200),
        ]);

        app(WhatsappService::class)->sendText('254712345678', 'hello');

        Http::assertSent(function ($req) {
            $body = $req->data();
            return str_contains($req->url(), 'graph.facebook.com/v20.0/123456789/messages')
                && $req->hasHeader('Authorization', 'Bearer test_token')
                && $body['messaging_product'] === 'whatsapp'
                && $body['to'] === '254712345678'
                && $body['type'] === 'text'
                && $body['text']['body'] === 'hello';
        });
    }

    public function test_send_text_throws_when_credentials_are_missing(): void
    {
        config()->set('whatsapp.access_token', null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('credentials missing');

        app(WhatsappService::class)->sendText('254712345678', 'hi');
    }

    public function test_send_template_includes_body_parameters_in_meta_format(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]], 200),
        ]);

        app(WhatsappService::class)->sendTemplate('254712345678', 'daily_summary', ['2026-04-29', 'KES 1000', 'KES 200']);

        Http::assertSent(function ($req) {
            $body = $req->data();
            $params = $body['template']['components'][0]['parameters'] ?? [];
            return $body['type'] === 'template'
                && $body['template']['name'] === 'daily_summary'
                && count($params) === 3
                && $params[0] === ['type' => 'text', 'text' => '2026-04-29']
                && $params[1] === ['type' => 'text', 'text' => 'KES 1000'];
        });
    }

    // ----- Daily summary command -----

    public function test_summary_command_sends_text_with_the_days_numbers(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.S']]], 200),
        ]);

        $waiter = User::factory()->waiter()->create();
        $this->paidSession($waiter, '2026-04-29 12:00', [['chips', 2]], 'cash');   // 300
        $this->paidSession($waiter, '2026-04-29 14:00', [['soda', 3]], 'mpesa');  // 240

        $this->artisan('summary:send-daily')->assertSuccessful();

        Http::assertSent(function ($req) {
            $body = $req->data();
            return $body['to'] === '254712345678'
                && $body['type'] === 'text'
                && str_contains($body['text']['body'], '2026-04-29')
                && str_contains($body['text']['body'], '540') // revenue
                && str_contains($body['text']['body'], 'Cash')
                && str_contains($body['text']['body'], 'M-Pesa');
        });
    }

    public function test_summary_command_uses_template_when_configured(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.T']]], 200),
        ]);

        config()->set('whatsapp.daily_summary_template', 'restaurant_daily');

        $waiter = User::factory()->waiter()->create();
        $this->paidSession($waiter, '2026-04-29 12:00', [['chips', 2]], 'cash');

        $this->artisan('summary:send-daily')->assertSuccessful();

        Http::assertSent(function ($req) {
            $body = $req->data();
            return $body['type'] === 'template'
                && $body['template']['name'] === 'restaurant_daily'
                && count($body['template']['components'][0]['parameters']) === 6;
        });
    }

    public function test_summary_command_skips_when_disabled(): void
    {
        Http::fake();
        config()->set('whatsapp.enabled', false);

        $this->artisan('summary:send-daily')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_summary_command_fails_when_no_recipient_configured(): void
    {
        Http::fake();
        config()->set('whatsapp.owner_recipient', null);

        $this->artisan('summary:send-daily')->assertFailed();
        Http::assertNothingSent();
    }
}
