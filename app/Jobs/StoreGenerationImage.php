<?php

namespace App\Jobs;

use App\Models\Generation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Copies the finished image into our own storage.
 * Pixelforge's link stops working after 24 hours.
 */
class StoreGenerationImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * Seconds to wait before each new try.
     *
     * @var list<int>
     */
    public array $backoff = [5, 30, 120, 600];

    public function __construct(public Generation $generation) {}

    public function handle(): void
    {
        $generation = $this->generation;

        if ($generation->image_path !== null || $generation->image_url === null) {
            return;
        }

        $image = Http::timeout(30)->get($generation->image_url)->throw()->body();

        $path = "generations/{$generation->uuid}.png";
        Storage::put($path, $image);

        $generation->update(['image_path' => $path]);
    }
}
