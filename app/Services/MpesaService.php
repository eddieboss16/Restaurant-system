<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MpesaService
{
    public function __construct(private array $config)
    {
    }

    public function getAccessToken(): string
    {
        return Cache::remember('mpesa.access_token', $this->config['token_ttl_seconds'], function () {
            $this->ensureCredentials();

            $response = Http::withBasicAuth($this->config['consumer_key'], $this->config['consumer_secret'])
                ->get($this->config['base_url'].'/oauth/v1/generate?grant_type=client_credentials');

            if (! $response->successful() || ! $response->json('access_token')) {
                throw new RuntimeException('Failed to get M-Pesa access token: '.$response->body());
            }

            return $response->json('access_token');
        });
    }

    /**
     * @return array{merchant_request_id: string, checkout_request_id: string}
     */
    public function initiateStkPush(string $phone, float $amount, string $accountReference, string $description): array
    {
        $this->ensureCredentials();

        if (empty($this->config['callback_url'])) {
            throw new RuntimeException('M-Pesa callback URL is not configured. Set MPESA_CALLBACK_URL in .env.');
        }

        $timestamp = now()->format('YmdHis');
        $password = base64_encode($this->config['shortcode'].$this->config['passkey'].$timestamp);
        $normalizedPhone = $this->normalizePhone($phone);

        $response = Http::withToken($this->getAccessToken())
            ->post($this->config['base_url'].'/mpesa/stkpush/v1/processrequest', [
                'BusinessShortCode' => $this->config['shortcode'],
                'Password' => $password,
                'Timestamp' => $timestamp,
                'TransactionType' => $this->config['transaction_type'],
                'Amount' => (int) round($amount),
                'PartyA' => $normalizedPhone,
                'PartyB' => $this->config['shortcode'],
                'PhoneNumber' => $normalizedPhone,
                'CallBackURL' => $this->config['callback_url'],
                'AccountReference' => substr($accountReference, 0, 12),
                'TransactionDesc' => substr($description, 0, 13),
            ]);

        if (! $response->successful() || $response->json('ResponseCode') !== '0') {
            throw new RuntimeException(
                'STK push failed: '.($response->json('errorMessage')
                    ?? $response->json('ResponseDescription')
                    ?? $response->body())
            );
        }

        return [
            'merchant_request_id' => (string) $response->json('MerchantRequestID'),
            'checkout_request_id' => (string) $response->json('CheckoutRequestID'),
        ];
    }

    /**
     * Convert any of "0712345678", "712345678", "+254712345678", "254712345678"
     * to Daraja's expected "254712345678" format.
     */
    public function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);

        if (str_starts_with($digits, '254')) {
            return $digits;
        }
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return '254'.substr($digits, 1);
        }
        if (strlen($digits) === 9) {
            return '254'.$digits;
        }

        return $digits;
    }

    private function ensureCredentials(): void
    {
        if (empty($this->config['consumer_key']) || empty($this->config['consumer_secret'])) {
            throw new RuntimeException(
                'M-Pesa Daraja credentials are missing. Set MPESA_CONSUMER_KEY and MPESA_CONSUMER_SECRET in your .env.'
            );
        }
    }
}
