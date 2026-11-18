<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Jobs\StoreGenerationImage;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Support\FakesPixelforge;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    private Generation $generation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakePixelforge();
        Bus::fake([StoreGenerationImage::class]); // do not download images in tests

        $this->generation = Generation::factory()->create();
    }

    public function test_a_wrong_signature_is_rejected_and_changes_nothing(): void
    {
        $remote = $this->remoteGeneration($this->generation, 'succeeded');

        $this->postWebhook('evt_1', 'generation.succeeded', $remote, secret: 'whsec_wrong')->assertStatus(400);

        $this->assertSame(GenerationStatus::Queued, $this->generation->refresh()->status);
        $this->assertSame(0, WebhookEvent::count());
        $this->assertSame(0, CreditTransaction::count());
    }

    public function test_an_old_webhook_is_rejected(): void
    {
        $remote = $this->remoteGeneration($this->generation, 'succeeded');

        $this->postWebhook('evt_1', 'generation.succeeded', $remote, timestamp: time() - 301)->assertStatus(400);

        $this->assertSame(0, CreditTransaction::count());
    }

    public function test_the_ping_test_event_is_accepted(): void
    {
        $this->postWebhook('evt_ping', 'ping')->assertNoContent();

        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_a_succeeded_image_is_charged_the_exact_price(): void
    {
        $this->postWebhook('evt_1', 'generation.succeeded', $this->remoteGeneration($this->generation, 'succeeded'))
            ->assertNoContent();

        $generation = $this->generation->refresh();

        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(self::EXAMPLE_COST, $generation->cost_nanos);
        $this->assertSame(self::EXAMPLE_PRICE, $generation->price_nanos);
        $this->assertSame('2026-11-18 10:00:20', $generation->completed_at->toDateTimeString());

        $charge = CreditTransaction::sole();
        $this->assertSame(-self::EXAMPLE_PRICE, $charge->amount_nanos);
        $this->assertSame($generation->user_id, $charge->user_id);

        Bus::assertDispatched(StoreGenerationImage::class);
    }

    public function test_the_same_event_sent_twice_charges_once(): void
    {
        $remote = $this->remoteGeneration($this->generation, 'succeeded');

        $this->postWebhook('evt_1', 'generation.succeeded', $remote)->assertNoContent();
        $this->postWebhook('evt_1', 'generation.succeeded', $remote)->assertNoContent();

        $this->assertSame(1, WebhookEvent::count());
        $this->assertSame(1, CreditTransaction::count());
    }

    public function test_two_different_succeeded_events_still_charge_once(): void
    {
        $remote = $this->remoteGeneration($this->generation, 'succeeded');

        $this->postWebhook('evt_1', 'generation.succeeded', $remote)->assertNoContent();
        $this->postWebhook('evt_2', 'generation.succeeded', $remote)->assertNoContent();

        $this->assertSame(1, CreditTransaction::count());
    }

    public function test_events_in_the_wrong_order_end_in_the_right_state(): void
    {
        // "succeeded" arrives first, the older "processing" event arrives late.
        $this->postWebhook('evt_2', 'generation.succeeded', $this->remoteGeneration($this->generation, 'succeeded'));
        $this->postWebhook('evt_1', 'generation.processing', $this->remoteGeneration($this->generation, 'processing'));

        $generation = $this->generation->refresh();

        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(self::EXAMPLE_PRICE, $generation->price_nanos);
        $this->assertSame(1, CreditTransaction::count());
    }

    public function test_a_failed_image_is_free(): void
    {
        $this->postWebhook('evt_1', 'generation.failed', $this->remoteGeneration($this->generation, 'failed'))
            ->assertNoContent();

        $generation = $this->generation->refresh();

        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame(0, $generation->price_nanos);
        $this->assertSame('No image was produced.', $generation->error_message);
        $this->assertSame(0, CreditTransaction::count());
    }

    public function test_a_failed_image_can_never_become_succeeded(): void
    {
        $this->postWebhook('evt_1', 'generation.failed', $this->remoteGeneration($this->generation, 'failed'));
        $this->postWebhook('evt_2', 'generation.succeeded', $this->remoteGeneration($this->generation, 'succeeded'));

        $this->assertSame(GenerationStatus::Failed, $this->generation->refresh()->status);
        $this->assertSame(0, CreditTransaction::count());
    }

    public function test_a_webhook_that_arrives_before_the_create_response_is_matched(): void
    {
        // Our row has no Pixelforge id yet. The uuid in the metadata finds it.
        $this->assertNull($this->generation->pixelforge_id);

        $this->postWebhook('evt_1', 'generation.processing', $this->remoteGeneration($this->generation, 'processing'));

        $generation = $this->generation->refresh();

        $this->assertSame(GenerationStatus::Processing, $generation->status);
        $this->assertSame('gen_'.$generation->id, $generation->pixelforge_id);
    }

    public function test_a_webhook_for_an_unknown_image_is_accepted_and_ignored(): void
    {
        $remote = $this->remoteGeneration($this->generation, 'succeeded');
        $remote['metadata']['generation_uuid'] = 'not-ours';

        $this->postWebhook('evt_1', 'generation.succeeded', $remote)->assertNoContent();

        $this->assertSame(0, CreditTransaction::count());
    }
}
