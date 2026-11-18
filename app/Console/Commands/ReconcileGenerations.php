<?php

namespace App\Console\Commands;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\GenerationUpdater;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use Illuminate\Console\Command;

/**
 * Safety net for lost webhooks. Runs every minute and asks Pixelforge
 * about images that have been unfinished for too long.
 */
class ReconcileGenerations extends Command
{
    protected $signature = 'pixelforge:reconcile';

    protected $description = 'Ask Pixelforge about images that are still not finished';

    /** Polling counts toward the 60 requests per minute limit, so stay small. */
    private const MAX_PER_RUN = 20;

    public function handle(PixelforgeClient $pixelforge, GenerationUpdater $updater): int
    {
        $stale = Generation::whereIn('status', GenerationStatus::pendingValues())
            ->whereNotNull('pixelforge_id')
            ->where('updated_at', '<', now()->subMinutes(2))
            ->oldest('updated_at')
            ->limit(self::MAX_PER_RUN)
            ->get();

        foreach ($stale as $generation) {
            try {
                $updater->apply($generation, $pixelforge->findGeneration($generation->pixelforge_id));
            } catch (PixelforgeException $e) {
                // Rate limited or down. Stop now, the next run tries again.
                $this->warn("Stopped early: {$e->getMessage()}");

                return self::FAILURE;
            }
        }

        $this->info("Checked {$stale->count()} generation(s).");

        return self::SUCCESS;
    }
}
