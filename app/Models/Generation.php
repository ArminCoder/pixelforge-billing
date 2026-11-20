<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use Carbon\CarbonImmutable;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One image request. All *_nanos columns are nanodollars (see App\Support\Money).
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string $prompt
 * @property string $size
 * @property string $quality
 * @property list<string> $reference_images
 * @property GenerationStatus $status
 * @property string|null $pixelforge_id
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property string|null $error_code
 * @property string|null $error_message
 * @property int $estimated_price_min_nanos
 * @property int $estimated_price_max_nanos
 * @property int|null $cost_nanos
 * @property int|null $price_nanos
 * @property array<string, int>|null $usage
 * @property array<string, string>|null $rates
 * @property string|null $image_url
 * @property string|null $image_path
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory, HasUuids;

    /**
     * Error code we set when we gave up sending the request and do not know
     * if Pixelforge created the image. Unlike a real failure, this one may
     * still be corrected by a later webhook.
     */
    public const SUBMISSION_UNCONFIRMED = 'submission_unconfirmed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'reference_images' => 'array',
            'usage' => 'array',
            'rates' => 'array',
            'submitted_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'estimated_price_min_nanos' => 'integer',
            'estimated_price_max_nanos' => 'integer',
            'cost_nanos' => 'integer',
            'price_nanos' => 'integer',
        ];
    }

    /**
     * The uuid is what we send to Pixelforge and show in URLs. The id stays internal.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * True when the final result is known for sure and must never change.
     */
    public function isSettled(): bool
    {
        return $this->status->isFinal()
            && $this->error_code !== self::SUBMISSION_UNCONFIRMED;
    }
}
