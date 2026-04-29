<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class WhatsappService
{
    public function __construct(private array $config)
    {
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false);
    }

    /**
     * Send a plain text message. Only works inside a 24h conversation
     * window or with whitelisted sandbox recipients. For production,
     * use sendTemplate with a pre-approved template.
     */
    public function sendText(string $to, string $body): array
    {
        $this->ensureCredentials();

        $response = Http::withToken($this->config['access_token'])
            ->post($this->endpoint(), [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'text',
                'text' => ['body' => $body],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'WhatsApp send failed: '.($response->json('error.message') ?? $response->body())
            );
        }

        return $response->json();
    }

    /**
     * Send a template message. Required for production messages outside the
     * 24h conversation window. Template must be pre-approved in Meta Business
     * Manager and parameters must match the template's variable count.
     *
     * @param  array<int, string>  $bodyParameters  Values for {{1}}, {{2}}, ... in the template body
     */
    public function sendTemplate(string $to, string $templateName, array $bodyParameters, string $language = 'en'): array
    {
        $this->ensureCredentials();

        $response = Http::withToken($this->config['access_token'])
            ->post($this->endpoint(), [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'template',
                'template' => [
                    'name' => $templateName,
                    'language' => ['code' => $language],
                    'components' => [[
                        'type' => 'body',
                        'parameters' => array_map(
                            fn ($v) => ['type' => 'text', 'text' => (string) $v],
                            $bodyParameters
                        ),
                    ]],
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'WhatsApp template send failed: '.($response->json('error.message') ?? $response->body())
            );
        }

        return $response->json();
    }

    private function endpoint(): string
    {
        return sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            $this->config['graph_version'],
            $this->config['phone_number_id'],
        );
    }

    private function ensureCredentials(): void
    {
        if (empty($this->config['access_token']) || empty($this->config['phone_number_id'])) {
            throw new RuntimeException(
                'WhatsApp credentials missing. Set WHATSAPP_ACCESS_TOKEN and WHATSAPP_PHONE_NUMBER_ID in your .env.'
            );
        }
    }
}
