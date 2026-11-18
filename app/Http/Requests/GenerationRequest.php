<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The same rules for the estimate box and the real submit,
 * so the estimate is always for a request we would accept.
 */
class GenerationRequest extends FormRequest
{
    public const SIZES = ['1024x1024', '1536x1024', '1024x1536'];

    public const QUALITIES = ['low', 'medium', 'high'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', Rule::in(self::SIZES)],
            'quality' => ['required', Rule::in(self::QUALITIES)],
            'reference_images' => ['array', 'max:4'],
            'reference_images.*' => ['string', 'url:http,https', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reference_images.max' => 'You can add up to 4 reference images.',
            'reference_images.*.url' => 'Each reference image must be a full http or https link.',
        ];
    }

    /** @return list<string> */
    public function referenceImages(): array
    {
        return array_values($this->validated('reference_images', []));
    }
}
