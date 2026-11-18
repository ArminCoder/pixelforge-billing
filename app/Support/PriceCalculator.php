<?php

namespace App\Support;

/**
 * Pure money math. No database, no HTTP, no config.
 *
 * Words used here:
 *  - cost:  what Pixelforge charges us
 *  - price: what the user pays (cost plus markup)
 */
final class PriceCalculator
{
    /** Pixelforge rates are in USD per this many tokens. */
    private const TOKENS_PER_RATE = 1_000_000;

    /** The four token types Pixelforge bills for. */
    private const BILLED_TOKENS = [
        'input_text_tokens',
        'input_image_tokens',
        'output_tokens',
        'reasoning_tokens',
    ];

    /**
     * Cost of one image in nanodollars.
     *
     * @param  array<string, int>  $usage  token counts, e.g. ['output_tokens' => 6450, ...]
     * @param  array<string, string>  $rates  USD per 1M tokens as strings, e.g. ['output_tokens' => '40.00', ...]
     */
    public static function cost(array $usage, array $rates): int
    {
        $total = 0;

        foreach (self::BILLED_TOKENS as $type) {
            // tokens x (nanodollars per 1M tokens). Still an exact integer.
            $total += (int) ($usage[$type] ?? 0) * Money::fromDecimal($rates[$type]);
        }

        // With Pixelforge's rates this divides with no remainder.
        return Money::divideRoundingUp($total, self::TOKENS_PER_RATE);
    }

    /**
     * Price for the user: cost plus the markup, rounded up to a whole nanodollar.
     */
    public static function price(int $cost, int $markupPercent): int
    {
        return Money::divideRoundingUp($cost * (100 + $markupPercent), 100);
    }

    /**
     * Lowest and highest possible cost before the image is made.
     *
     * Two things are unknown up front: the exact text token count
     * (it can be off by a few percent) and the reasoning tokens.
     *
     * @param  array<string, mixed>  $pricing  the body of GET /v1/pricing
     * @return array{min: int, max: int}
     */
    public static function estimateCost(
        array $pricing,
        string $prompt,
        string $size,
        string $quality,
        int $referenceImages,
    ): array {
        $tokens = $pricing['tokens'];

        $textTokens = Money::divideRoundingUp(mb_strlen($prompt), $tokens['characters_per_text_token']);
        $variancePercent = (int) round($tokens['text_token_variance'] * 100);

        $known = [
            'input_image_tokens' => $referenceImages * $tokens['per_reference_image'],
            'output_tokens' => $tokens['output'][$quality][$size],
        ];

        $lowest = $known + [
            'input_text_tokens' => intdiv($textTokens * (100 - $variancePercent), 100),
            'reasoning_tokens' => $tokens['reasoning'][$quality]['min'],
        ];

        $highest = $known + [
            'input_text_tokens' => Money::divideRoundingUp($textTokens * (100 + $variancePercent), 100),
            'reasoning_tokens' => $tokens['reasoning'][$quality]['max'],
        ];

        return [
            'min' => self::cost($lowest, $pricing['rates']),
            'max' => self::cost($highest, $pricing['rates']),
        ];
    }
}
