<?php

namespace App\Http\Resources;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the history page gets for one image. Money stays in nanodollars;
 * the browser only formats it.
 *
 * @mixin Generation
 */
class GenerationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => count($this->reference_images),
            'status' => $this->status->value,
            // Still in our own queue, not yet accepted by Pixelforge.
            'waiting_for_slot' => $this->status === GenerationStatus::Queued && $this->submitted_at === null,
            'estimated_price_min' => $this->estimated_price_min_nanos,
            'estimated_price_max' => $this->estimated_price_max_nanos,
            'price' => $this->price_nanos,
            'error' => $this->error_message,
            'image' => $this->status === GenerationStatus::Succeeded
                ? route('generations.image', $this->resource)
                : null,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
