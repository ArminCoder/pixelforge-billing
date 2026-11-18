<?php

namespace Tests\Unit;

use App\Support\WebhookSignature;
use PHPUnit\Framework\TestCase;

class WebhookSignatureTest extends TestCase
{
    private const SECRET = 'whsec_test';

    private const NOW = 1_800_000_000;

    private function header(string $body, int $timestamp, string $secret = self::SECRET): string
    {
        return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
    }

    public function test_a_correct_signature_is_accepted(): void
    {
        $body = '{"id":"evt_1"}';

        $this->assertTrue(WebhookSignature::isValid($body, $this->header($body, self::NOW), self::SECRET, self::NOW));
    }

    public function test_a_changed_body_is_rejected(): void
    {
        $header = $this->header('{"id":"evt_1"}', self::NOW);

        $this->assertFalse(WebhookSignature::isValid('{"id":"evt_2"}', $header, self::SECRET, self::NOW));
    }

    public function test_a_wrong_secret_is_rejected(): void
    {
        $body = '{"id":"evt_1"}';
        $header = $this->header($body, self::NOW, 'whsec_someone_else');

        $this->assertFalse(WebhookSignature::isValid($body, $header, self::SECRET, self::NOW));
    }

    public function test_a_signature_older_than_five_minutes_is_rejected(): void
    {
        $body = '{"id":"evt_1"}';

        $this->assertTrue(WebhookSignature::isValid($body, $this->header($body, self::NOW - 300), self::SECRET, self::NOW));
        $this->assertFalse(WebhookSignature::isValid($body, $this->header($body, self::NOW - 301), self::SECRET, self::NOW));
    }

    public function test_missing_or_broken_headers_are_rejected(): void
    {
        $this->assertFalse(WebhookSignature::isValid('{}', null, self::SECRET, self::NOW));
        $this->assertFalse(WebhookSignature::isValid('{}', 'nonsense', self::SECRET, self::NOW));
        $this->assertFalse(WebhookSignature::isValid('{}', 't=abc,v1=def', self::SECRET, self::NOW));
    }

    public function test_an_empty_secret_never_passes(): void
    {
        $body = '{}';

        $this->assertFalse(WebhookSignature::isValid($body, $this->header($body, self::NOW, ''), '', self::NOW));
    }
}
