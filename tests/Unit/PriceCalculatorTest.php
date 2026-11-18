<?php

namespace Tests\Unit;

use App\Support\PriceCalculator;
use PHPUnit\Framework\TestCase;

class PriceCalculatorTest extends TestCase
{
    private const RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    private const PRICING = [
        'rates' => self::RATES,
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

    /**
     * The worked example from the Pixelforge docs:
     * high quality, 1536x1024, 2 reference images, most reasoning.
     * It must come out to exactly $0.373875000.
     */
    public function test_cost_matches_the_worked_example_from_the_docs(): void
    {
        $cost = PriceCalculator::cost([
            'input_text_tokens' => 115,
            'input_image_tokens' => 1530,
            'output_tokens' => 6450,
            'reasoning_tokens' => 8000,
        ], self::RATES);

        $this->assertSame(373_875_000, $cost);
    }

    public function test_the_smallest_token_cost_is_still_exact(): void
    {
        // One reasoning token costs $0.0000125. Microdollars could not hold this.
        $cost = PriceCalculator::cost(['reasoning_tokens' => 1], self::RATES);

        $this->assertSame(12_500, $cost);
    }

    public function test_price_is_cost_plus_25_percent(): void
    {
        $this->assertSame(467_343_750, PriceCalculator::price(373_875_000, 25));
        $this->assertSame(0, PriceCalculator::price(0, 25));
    }

    public function test_price_rounds_up_when_the_markup_leaves_a_fraction(): void
    {
        // 1 x 1.25 = 1.25 nanodollars. We never charge less than cost plus markup.
        $this->assertSame(2, PriceCalculator::price(1, 25));
    }

    public function test_estimate_range_for_the_worked_example(): void
    {
        $estimate = PriceCalculator::estimateCost(
            self::PRICING,
            prompt: str_repeat('a', 400),
            size: '1536x1024',
            quality: 'high',
            referenceImages: 2,
        );

        // Highest: 115 text tokens and 8,000 reasoning tokens.
        $this->assertSame(373_875_000, $estimate['max']);
        // Lowest: 85 text tokens and 1,000 reasoning tokens.
        $this->assertSame(286_225_000, $estimate['min']);
    }

    public function test_estimate_counts_characters_not_bytes(): void
    {
        // 8 characters, but 16 bytes in UTF-8. Must count as 2 text tokens.
        $ascii = PriceCalculator::estimateCost(self::PRICING, 'aaaaaaaa', '1024x1024', 'low', 0);
        $unicode = PriceCalculator::estimateCost(self::PRICING, 'ćććććććć', '1024x1024', 'low', 0);

        $this->assertSame($ascii, $unicode);
    }
}
