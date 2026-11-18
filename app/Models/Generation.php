<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One image request. All *_nanos columns are nanodollars (see App\Support\Money).
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

    /** The uuid is what we send to Pixelforge and show in URLs. The id stays internal. */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

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
