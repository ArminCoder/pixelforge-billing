<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Jobs\StoreGenerationImage;
use App\Models\CreditTransaction;
use App\Models\Generation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesPixelforge;
use Tests\TestCase;

/** The polling backup for lost webhooks. */
class ReconcileGenerationsTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([StoreGenerationImage::class]);
    }

    /** A row Pixelforge accepted, last changed $minutesAgo minutes ago. */
    private function pending(int $minutesAgo): Generation
    {
        $generation = Generation::factory()->create(['submitted_at' => now()]);
        $generation->pixelforge_id = 'gen_'.$generation->id;
        $generation->updated_at = now()->subMinutes($minutesAgo);
        $generation->saveQuietly(['timestamps' => false]);

        return $generation;
    }

    private function url(Generation $generation): string
    {
        return "pixelforge.test/v1/generations/{$generation->pixelforge_id}";
    }

    public function test_a_lost_webhook_is_made_up_for_by_polling(): void
    {
        $stale = $this->pending(minutesAgo: 5);
        $fresh = $this->pending(minutesAgo: 0);

        $this->fakePixelforge([
            $this->url($stale) => Http::response($this->remoteGeneration($stale, 'succeeded')),
        ]);

        $this->artisan('pixelforge:reconcile')->assertSuccessful();

        $this->assertSame(GenerationStatus::Succeeded, $stale->refresh()->status);
        $this->assertSame(-self::EXAMPLE_PRICE, CreditTransaction::sole()->amount_nanos);

        // Changed just now, so its webhook may still come. Not asked about.
        $this->assertSame(GenerationStatus::Queued, $fresh->refresh()->status);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), $fresh->pixelforge_id));
    }

    public function test_a_rate_limit_stops_the_run(): void
    {
        $first = $this->pending(minutesAgo: 10);
        $second = $this->pending(minutesAgo: 5);

        $this->fakePixelforge([
            $this->url($first) => Http::response(['error' => ['code' => 'rate_limit_exceeded']], 429),
            $this->url($second) => Http::response($this->remoteGeneration($second, 'succeeded')),
        ]);

        $this->artisan('pixelforge:reconcile')->assertFailed();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), $second->pixelforge_id));
    }

    public function test_an_image_pixelforge_cannot_find_does_not_block_the_others(): void
    {
        $missing = $this->pending(minutesAgo: 10);
        $next = $this->pending(minutesAgo: 5);

        $this->fakePixelforge([
            $this->url($missing) => Http::response(['error' => ['code' => 'not_found']], 404),
            $this->url($next) => Http::response($this->remoteGeneration($next, 'succeeded')),
        ]);

        $this->artisan('pixelforge:reconcile')->assertSuccessful();

        $this->assertSame(GenerationStatus::Queued, $missing->refresh()->status);
        $this->assertSame(GenerationStatus::Succeeded, $next->refresh()->status);
    }
}
