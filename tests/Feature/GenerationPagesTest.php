<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Credits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakesPixelforge;
use Tests\TestCase;

class GenerationPagesTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    /** The worked example from the docs, as form input. */
    private const FORM = [
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
    ];

    /** The example's cost range ($0.286225 to $0.373875) plus 25%. */
    private const PRICE_MIN = 357_781_250;

    private const PRICE_MAX = 467_343_750;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakePixelforge();
        Queue::fake();

        $this->user = User::factory()->create();
    }

    private function form(): array
    {
        return self::FORM + ['prompt' => str_repeat('a', 400)];
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/generations')->assertRedirect(route('login'));
        $this->post('/generations', $this->form())->assertRedirect(route('login'));
    }

    public function test_the_estimate_is_a_price_range_with_the_markup(): void
    {
        $this->actingAs($this->user)
            ->postJson('/generations/estimate', $this->form())
            ->assertOk()
            ->assertExactJson(['min' => self::PRICE_MIN, 'max' => self::PRICE_MAX]);
    }

    public function test_the_estimate_rejects_a_bad_form(): void
    {
        $this->actingAs($this->user)
            ->postJson('/generations/estimate', ['prompt' => 'x', 'size' => '1x1', 'quality' => 'high'])
            ->assertStatus(422);
    }

    public function test_a_request_is_blocked_without_enough_credit(): void
    {
        // One nanodollar short of the highest possible price.
        app(Credits::class)->purchase($this->user, self::PRICE_MAX - 1);

        $this->actingAs($this->user)
            ->post('/generations', $this->form())
            ->assertSessionHasErrors('credits');

        $this->assertSame(0, Generation::count());
        Queue::assertNothingPushed();
    }

    public function test_a_request_is_saved_with_its_estimate_and_queued(): void
    {
        app(Credits::class)->purchase($this->user, self::PRICE_MAX);

        $this->actingAs($this->user)
            ->post('/generations', $this->form())
            ->assertRedirect(route('generations.index'));

        $generation = Generation::sole();

        $this->assertSame($this->user->id, $generation->user_id);
        $this->assertSame(self::PRICE_MIN, $generation->estimated_price_min_nanos);
        $this->assertSame(self::PRICE_MAX, $generation->estimated_price_max_nanos);

        Queue::assertPushed(SubmitGeneration::class);
    }

    public function test_credit_held_for_an_unfinished_image_cannot_be_spent_twice(): void
    {
        app(Credits::class)->purchase($this->user, self::PRICE_MAX);

        $this->actingAs($this->user)->post('/generations', $this->form());
        $this->actingAs($this->user)->post('/generations', $this->form())->assertSessionHasErrors('credits');

        $this->assertSame(1, Generation::count());
    }

    public function test_buying_credits_adds_one_pack_to_the_balance(): void
    {
        $this->actingAs($this->user)->post('/credits');

        $this->assertSame(config('pixelforge.credit_pack_nanos'), app(Credits::class)->balance($this->user));
    }

    public function test_users_cannot_see_each_others_images(): void
    {
        $other = Generation::factory()->create(['image_url' => 'https://images.pixelforge.test/x.png']);

        $this->actingAs($this->user)->get("/generations/{$other->uuid}/image")->assertNotFound();
    }

    public function test_an_image_link_that_is_not_a_uuid_is_not_found(): void
    {
        $this->actingAs($this->user)->get('/generations/not-a-uuid/image')->assertNotFound();
    }

    public function test_the_admin_report_is_only_for_admins(): void
    {
        $this->actingAs($this->user)->get('/admin/report')->assertForbidden();

        config(['pixelforge.admin_emails' => [$this->user->email]]);

        $this->actingAs($this->user)->get('/admin/report')->assertOk();
    }

    public function test_the_admin_report_adds_up_succeeded_images_per_completion_day(): void
    {
        config(['pixelforge.admin_emails' => [$this->user->email]]);

        $succeeded = fn (string $completedAt) => [
            'user_id' => $this->user->id,
            'status' => GenerationStatus::Succeeded,
            'completed_at' => $completedAt,
            'cost_nanos' => 100_000_000,
            'price_nanos' => 125_000_000,
        ];

        Generation::factory()->create($succeeded('2026-11-17 23:59:00'));
        Generation::factory()->create($succeeded('2026-11-18 00:01:00'));
        Generation::factory()->create($succeeded('2026-11-18 10:00:00'));
        Generation::factory()->create(['user_id' => $this->user->id, 'status' => GenerationStatus::Failed]);

        $this->actingAs($this->user)->get('/admin/report')->assertInertia(fn ($page) => $page
            ->where('byDay', [
                ['label' => '2026-11-18', 'images' => 2, 'paid' => 250_000_000, 'cost' => 200_000_000, 'profit' => 50_000_000],
                ['label' => '2026-11-17', 'images' => 1, 'paid' => 125_000_000, 'cost' => 100_000_000, 'profit' => 25_000_000],
            ])
            ->where('byUser', [
                ['label' => $this->user->email, 'images' => 3, 'paid' => 375_000_000, 'cost' => 300_000_000, 'profit' => 75_000_000],
            ]));
    }
}
