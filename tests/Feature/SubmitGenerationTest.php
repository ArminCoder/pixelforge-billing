<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Jobs\StoreGenerationImage;
use App\Jobs\SubmitGeneration;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Services\GenerationUpdater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesPixelforge;
use Tests\TestCase;

class SubmitGenerationTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    private const CREATE_URL = 'pixelforge.test/v1/generations';

    private function runJob(Generation $generation): SubmitGeneration
    {
        $job = (new SubmitGeneration($generation))->withFakeQueueInteractions();

        app()->call([$job, 'handle']);

        return $job;
    }

    private function errorResponse(int $status, string $code, array $headers = [])
    {
        return Http::response(['error' => ['type' => 'error', 'code' => $code, 'message' => 'Nope.']], $status, $headers);
    }

    public function test_it_sends_the_request_with_an_idempotency_key_and_our_uuid(): void
    {
        $generation = Generation::factory()->create(['reference_images' => ['https://example.com/a.png']]);

        $this->fakePixelforge([
            self::CREATE_URL => Http::response($this->remoteGeneration($generation, 'queued'), 202),
        ]);

        $this->runJob($generation)->assertNotReleased();

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->hasHeader('Idempotency-Key', $generation->uuid)
            && $request['metadata']['generation_uuid'] === $generation->uuid
            && $request['reference_images'] === ['https://example.com/a.png']);

        $generation->refresh();

        $this->assertSame(GenerationStatus::Queued, $generation->status);
        $this->assertSame('gen_'.$generation->id, $generation->pixelforge_id);
        $this->assertNotNull($generation->submitted_at);
    }

    public function test_it_waits_and_tries_again_when_three_images_are_already_running(): void
    {
        $generation = Generation::factory()->create();

        $this->fakePixelforge([
            self::CREATE_URL => $this->errorResponse(429, 'concurrency_limit_exceeded', ['Retry-After' => '5']),
        ]);

        $this->runJob($generation)->assertReleased(delay: 5);

        $this->assertSame(GenerationStatus::Queued, $generation->refresh()->status);
        $this->assertNull($generation->submitted_at);
    }

    public function test_it_tries_again_when_pixelforge_is_overloaded(): void
    {
        $generation = Generation::factory()->create();

        $this->fakePixelforge([
            self::CREATE_URL => $this->errorResponse(503, 'overloaded', ['Retry-After' => '2']),
        ]);

        $this->runJob($generation)->assertReleased(delay: 2);
    }

    public function test_it_waits_as_long_as_the_rate_limit_asks(): void
    {
        $generation = Generation::factory()->create();

        $this->fakePixelforge([
            self::CREATE_URL => $this->errorResponse(429, 'rate_limit_exceeded', ['Retry-After' => '17']),
        ]);

        $this->runJob($generation)->assertReleased(delay: 17);
    }

    public function test_it_tries_again_when_pixelforge_does_not_answer(): void
    {
        $generation = Generation::factory()->create();

        $this->fakePixelforge([
            self::CREATE_URL => Http::failedConnection(),
        ]);

        // No Retry-After without an answer, so the job waits 5 seconds.
        $this->runJob($generation)->assertReleased(delay: 5);

        $this->assertSame(GenerationStatus::Queued, $generation->refresh()->status);
    }

    public function test_a_rejected_prompt_fails_right_away_and_is_free(): void
    {
        $generation = Generation::factory()->create();

        $this->fakePixelforge([
            self::CREATE_URL => $this->errorResponse(400, 'content_policy_violation'),
        ]);

        $this->runJob($generation)->assertNotReleased();

        $generation->refresh();

        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame(0, $generation->price_nanos);
        $this->assertStringContainsString('rejected this prompt', $generation->error_message);
        $this->assertSame(0, CreditTransaction::count());
    }

    public function test_the_daily_limit_fails_instead_of_waiting_until_tomorrow(): void
    {
        $generation = Generation::factory()->create();

        $this->fakePixelforge([
            self::CREATE_URL => $this->errorResponse(429, 'daily_limit_exceeded', ['Retry-After' => '3600']),
        ]);

        $this->runJob($generation)->assertNotReleased();

        $this->assertSame(GenerationStatus::Failed, $generation->refresh()->status);
    }

    public function test_giving_up_can_still_be_corrected_if_pixelforge_made_the_image(): void
    {
        Bus::fake([StoreGenerationImage::class]);
        $this->fakePixelforge();

        $generation = Generation::factory()->create();

        // The job ran out of time without a clear answer.
        (new SubmitGeneration($generation))->failed(null);

        $this->assertSame(GenerationStatus::Failed, $generation->refresh()->status);
        $this->assertFalse($generation->isSettled());

        // Later we learn that Pixelforge did create and finish it.
        app(GenerationUpdater::class)->apply($generation, $this->remoteGeneration($generation, 'succeeded'));

        $generation->refresh();

        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertNull($generation->error_message);
        $this->assertSame(self::EXAMPLE_PRICE, $generation->price_nanos);
        $this->assertSame(1, CreditTransaction::count());
    }
}
