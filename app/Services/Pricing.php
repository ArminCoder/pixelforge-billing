<?php

namespace App\Services;

use App\Services\Pixelforge\PixelforgeClient;
use App\Support\PriceCalculator;
use Illuminate\Support\Facades\Cache;

/**
 * Prices as the app sees them. The math itself lives in PriceCalculator;
 * this class only adds the live pricing table and our markup.
 */
class Pricing
{
    public function __construct(private readonly PixelforgeClient $pixelforge) {}

    /**
     * Lowest and highest price the user might pay, in nanodollars.
     *
     * @return array{min: int, max: int}
     */
    public function estimatePrice(string $prompt, string $size, string $quality, int $referenceImages): array
    {
        $cost = PriceCalculator::estimateCost($this->table(), $prompt, $size, $quality, $referenceImages);

        return [
            'min' => $this->price($cost['min']),
            'max' => $this->price($cost['max']),
        ];
    }

    /** What the user pays for an image that cost us $cost nanodollars. */
    public function price(int $cost): int
    {
        return PriceCalculator::price($cost, config('pixelforge.markup_percent'));
    }

    /**
     * Pixelforge's public pricing table. Cached for a few minutes so the
     * estimate box does not use up our rate limit.
     *
     * @return array<string, mixed>
     */
    private function table(): array
    {
        return Cache::remember('pixelforge.pricing', now()->addMinutes(10), fn () => $this->pixelforge->pricing());
    }
}
