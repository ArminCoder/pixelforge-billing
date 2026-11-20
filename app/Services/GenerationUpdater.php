<?php

namespace App\Services;

use App\Enums\GenerationStatus;
use App\Jobs\StoreGenerationImage;
use App\Models\Generation;
use App\Support\PriceCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The one place where a generation changes status and money is charged.
 *
 * Webhooks, the create response and the polling command all call apply()
 * with a generation object from Pixelforge. Because they share this code,
 * it does not matter which of them arrives first, twice, or late.
 */
class GenerationUpdater
{
    public function __construct(
        private readonly Pricing $pricing,
        private readonly Credits $credits,
    ) {}

    /**
     * Bring our row up to date with what Pixelforge says.
     *
     * @param  array<string, mixed>  $remote  a generation object from Pixelforge
     */
    public function apply(Generation $generation, array $remote): void
    {
        DB::transaction(function () use ($generation, $remote) {
            // Lock the row so two webhooks for the same image wait for each other.
            $generation = Generation::whereKey($generation->id)->lockForUpdate()->firstOrFail();

            // Rule 1: a finished image never changes again.
            if ($generation->isSettled()) {
                return;
            }

            $generation->pixelforge_id ??= $remote['id'];
            $generation->submitted_at ??= now();

            $newStatus = GenerationStatus::from($remote['status']);

            // We gave up on this one, but Pixelforge did create it. Trust Pixelforge.
            $wasUnconfirmed = $generation->status->isFinal();

            // Rule 2: status only moves forward. Old or repeated news is ignored.
            if (! $wasUnconfirmed && $newStatus->rank() <= $generation->status->rank()) {
                $generation->save();

                return;
            }

            $generation->fill([
                'status' => $newStatus,
                'started_at' => $this->time($remote['started_at'] ?? null),
                'completed_at' => null,
                'error_code' => null,
                'error_message' => null,
                'cost_nanos' => null,
                'price_nanos' => null,
            ]);

            match ($newStatus) {
                GenerationStatus::Succeeded => $this->succeed($generation, $remote),
                GenerationStatus::Failed => $this->fail($generation, $remote),
                default => $generation->save(),
            };
        });
    }

    /**
     * Mark a generation as failed because of something on our side
     * (Pixelforge refused the request, or we could not reach it).
     */
    public function failLocally(Generation $generation, string $code, string $message): void
    {
        DB::transaction(function () use ($generation, $code, $message) {
            $generation = Generation::whereKey($generation->id)->lockForUpdate()->firstOrFail();

            // A webhook may have finished it in the meantime. Then leave it alone.
            if ($generation->status->isFinal()) {
                return;
            }

            $generation->update([
                'status' => GenerationStatus::Failed,
                'error_code' => $code,
                'error_message' => $message,
                'completed_at' => now(),
                'cost_nanos' => 0,
                'price_nanos' => 0,
            ]);
        });
    }

    /**
     * The image is ready: save the real cost and charge the user once.
     *
     * @param  array<string, mixed>  $remote  a generation object from Pixelforge
     */
    private function succeed(Generation $generation, array $remote): void
    {
        // Use the rates stored on this image, not today's prices.
        $cost = PriceCalculator::cost($remote['usage'], $remote['rates']);

        $generation->fill([
            'usage' => $remote['usage'],
            'rates' => $remote['rates'],
            'cost_nanos' => $cost,
            'price_nanos' => $this->pricing->price($cost),
            'image_url' => $remote['image_url'],
            'completed_at' => $this->time($remote['completed_at']),
        ])->save();

        $this->credits->charge($generation);

        // Pixelforge's image link dies after 24 hours, so keep our own copy.
        // afterCommit: the job must not start before this transaction is saved.
        StoreGenerationImage::dispatch($generation)->afterCommit();
    }

    /**
     * A failed image is free.
     *
     * @param  array<string, mixed>  $remote  a generation object from Pixelforge
     */
    private function fail(Generation $generation, array $remote): void
    {
        $generation->fill([
            'error_code' => $remote['error']['code'] ?? 'generation_failed',
            'error_message' => $remote['error']['message'] ?? 'Pixelforge could not make this image.',
            'cost_nanos' => 0,
            'price_nanos' => 0,
            'completed_at' => $this->time($remote['completed_at'] ?? null) ?? now(),
        ])->save();
    }

    private function time(?string $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value)->utc();
    }
}
