<?php

namespace Tests\Support;

use App\Models\Generation;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * Helpers shared by the feature tests: a fake Pixelforge API,
 * example generation objects, and correctly signed webhooks.
 */
trait FakesPixelforge
{
    protected const WEBHOOK_SECRET = 'whsec_test';

    /** Cost of exampleUsage() at the standard rates, in nanodollars. */
    protected const EXAMPLE_COST = 54_060_000;

    /** EXAMPLE_COST plus 25%. */
    protected const EXAMPLE_PRICE = 67_575_000;

    /**
     * Fake every HTTP call. Pass the answers for the calls a test cares
     * about; the pricing table is always there. A call that matches
     * nothing fails the test.
     *
     * @param  array<string, mixed>  $responses
     */
    protected function fakePixelforge(array $responses = []): void
    {
        config([
            'pixelforge.base_url' => 'https://pixelforge.test/v1',
            'pixelforge.api_key' => 'pf_test_key',
            'pixelforge.webhook_secret' => self::WEBHOOK_SECRET,
        ]);

        Http::preventStrayRequests();
        Http::fake($responses + ['pixelforge.test/v1/pricing' => Http::response($this->pricingTable())]);
    }

    /** The real body of GET /v1/pricing. */
    protected function pricingTable(): array
    {
        return [
            'object' => 'pricing',
            'currency' => 'usd',
            'unit' => 'per_1m_tokens',
            'rates' => $this->rates(),
            'tokens' => [
                'characters_per_text_token' => 4,
                'text_token_variance' => 0.15,
                'per_reference_image' => 765,
                'output' => [
                    'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                    'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                    'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
                ],
                'reasoning' => [
                    'low' => ['min' => 0, 'max' => 500],
                    'medium' => ['min' => 200, 'max' => 2000],
                    'high' => ['min' => 1000, 'max' => 8000],
                ],
            ],
        ];
    }

    protected function rates(): array
    {
        return [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ];
    }

    /** 12 x 5,000 + 1,100 x 40,000 + 800 x 12,500 = 54,060,000 nanodollars. */
    protected function exampleUsage(): array
    {
        return [
            'input_text_tokens' => 12,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 800,
            'total_tokens' => 1912,
        ];
    }

    /** A generation object the way Pixelforge would send it for our row. */
    protected function remoteGeneration(Generation $generation, string $status): array
    {
        $finished = in_array($status, ['succeeded', 'failed'], true);

        return [
            'id' => 'gen_'.$generation->id,
            'object' => 'generation',
            'status' => $status,
            'metadata' => ['generation_uuid' => $generation->uuid],
            'image_url' => $status === 'succeeded' ? 'https://images.pixelforge.test/image.png' : null,
            'usage' => $status === 'succeeded' ? $this->exampleUsage() : null,
            'rates' => $this->rates(),
            'error' => $status === 'failed'
                ? ['code' => 'generation_failed', 'message' => 'No image was produced.']
                : null,
            'started_at' => $status === 'queued' ? null : '2026-11-18T10:00:05Z',
            'completed_at' => $finished ? '2026-11-18T10:00:20Z' : null,
        ];
    }

    /** Send a webhook with a correct signature (unless told otherwise). */
    protected function postWebhook(
        string $eventId,
        string $type,
        array $object = [],
        string $secret = self::WEBHOOK_SECRET,
        ?int $timestamp = null,
    ): TestResponse {
        $body = json_encode([
            'id' => $eventId,
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => $object],
        ]);

        $timestamp ??= time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

        return $this->call('POST', '/api/webhooks/pixelforge', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], content: $body);
    }
}
