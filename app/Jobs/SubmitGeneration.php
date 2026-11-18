<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\GenerationUpdater;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends one image request to Pixelforge.
 *
 * Temporary problems (rate limit, 3 images already running, overload,
 * no answer) put the job back on the queue. Clear refusals fail the
 * image right away with a message for the user.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    public function __construct(public Generation $generation) {}

    /** Keep trying for 10 minutes, then give up. */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(10);
    }

    public function handle(PixelforgeClient $pixelforge, GenerationUpdater $updater): void
    {
        $generation = $this->generation;

        // Already sent, or already finished by a webhook. Nothing to do.
        if ($generation->submitted_at !== null || $generation->status->isFinal()) {
            return;
        }

        $params = [
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            // Comes back in every webhook, so we can find our row
            // even when the webhook is faster than this response.
            'metadata' => ['generation_uuid' => $generation->uuid],
        ];

        if ($generation->reference_images !== []) {
            $params['reference_images'] = $generation->reference_images;
        }

        try {
            $remote = $pixelforge->createGeneration($params, idempotencyKey: $generation->uuid);
        } catch (PixelforgeException $e) {
            if ($e->isRetryable()) {
                // Wait as long as Pixelforge asked, or 5 seconds.
                $this->release(max(1, $e->retryAfter ?? 5));

                return;
            }

            $updater->failLocally($generation, $e->errorCode ?? 'submission_failed', $e->userMessage());

            return;
        }

        $updater->apply($generation, $remote);
    }

    /**
     * Called when the 10 minutes are over. We do not know for sure that
     * Pixelforge never created the image, so this failure stays open to
     * correction by a later webhook (see Generation::isSettled).
     */
    public function failed(?Throwable $exception): void
    {
        app(GenerationUpdater::class)->failLocally(
            $this->generation,
            Generation::SUBMISSION_UNCONFIRMED,
            'Pixelforge was too busy to take this request. You were not charged. Please try again.',
        );
    }
}
