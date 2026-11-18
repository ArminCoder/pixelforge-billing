<?php

namespace App\Support;

/**
 * Checks that a webhook really comes from Pixelforge.
 *
 * Header format: "t=<unix timestamp>,v1=<hex signature>"
 * The signature is HMAC-SHA256 of "<timestamp>.<raw body>".
 */
final class WebhookSignature
{
    /** Older (or newer) than this many seconds is rejected, to stop replays. */
    public const TOLERANCE_SECONDS = 300;

    public static function isValid(string $rawBody, ?string $header, string $secret, int $now): bool
    {
        if ($header === null || $secret === '') {
            return false;
        }

        $parts = [];

        foreach (explode(',', $header) as $pair) {
            [$key, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
            $parts[$key] = $value;
        }

        $timestamp = $parts['t'] ?? '';
        $signature = $parts['v1'] ?? '';

        if (! ctype_digit($timestamp) || $signature === '') {
            return false;
        }

        if (abs($now - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);

        // hash_equals compares in constant time, so timing leaks nothing.
        return hash_equals($expected, $signature);
    }
}
