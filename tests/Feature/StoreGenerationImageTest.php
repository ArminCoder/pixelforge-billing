<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Jobs\StoreGenerationImage;
use App\Models\Generation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Pixelforge's image link dies after 24 hours, so we keep our own copy. */
class StoreGenerationImageTest extends TestCase
{
    use RefreshDatabase;

    private function succeeded(): Generation
    {
        return Generation::factory()->create([
            'status' => GenerationStatus::Succeeded,
            'image_url' => 'https://images.pixelforge.test/x.png',
        ]);
    }

    public function test_the_image_is_copied_to_our_storage(): void
    {
        Storage::fake();
        Http::fake(['images.pixelforge.test/*' => Http::response('png-bytes')]);
        $generation = $this->succeeded();

        (new StoreGenerationImage($generation))->handle();

        $path = "generations/{$generation->uuid}.png";
        $this->assertSame($path, $generation->refresh()->image_path);
        Storage::assertExists($path);
        $this->assertSame('png-bytes', Storage::get($path));
    }

    public function test_a_failed_download_throws_so_the_queue_tries_again(): void
    {
        Storage::fake();
        Http::fake(['images.pixelforge.test/*' => Http::response('', 500)]);
        $generation = $this->succeeded();

        $this->expectException(RequestException::class);

        try {
            (new StoreGenerationImage($generation))->handle();
        } finally {
            $this->assertNull($generation->refresh()->image_path);
        }
    }
}
