<?php

namespace App\Services\Pixelforge;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Any failed call to Pixelforge. It knows two things the rest of the app
 * needs: may we try again, and what do we tell the user.
 */
class PixelforgeException extends RuntimeException
{
    /**
     * @param  int|null  $status  HTTP status, or null when we got no answer at all
     * @param  string|null  $errorCode  Pixelforge's own code, e.g. "overloaded"
     * @param  int|null  $retryAfter  seconds Pixelforge asked us to wait
     */
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $errorCode = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(Response $response): self
    {
        $retryAfter = $response->header('Retry-After');

        return new self(
            message: $response->json('error.message') ?? 'Pixelforge returned an error.',
            status: $response->status(),
            errorCode: $response->json('error.code'),
            retryAfter: is_numeric($retryAfter) ? (int) $retryAfter : null,
        );
    }

    public static function noAnswer(string $reason): self
    {
        return new self("Could not reach Pixelforge: {$reason}");
    }

    /**
     * Trying again makes sense when the problem is temporary:
     * no answer, a server error, or a rate limit that resets soon.
     * The daily limit resets only at midnight UTC, so we do not wait for it.
     */
    public function isRetryable(): bool
    {
        if ($this->status === null || $this->status >= 500) {
            return true;
        }

        return $this->status === 429 && $this->errorCode !== 'daily_limit_exceeded';
    }

    /** A plain sentence for the history page. */
    public function userMessage(): string
    {
        return match ($this->errorCode) {
            'content_policy_violation' => 'Pixelforge rejected this prompt (content policy). You were not charged.',
            'daily_limit_exceeded' => 'The daily image limit is reached. Try again tomorrow. You were not charged.',
            'validation_failed' => 'Pixelforge did not accept this request: '.$this->getMessage(),
            default => 'Pixelforge could not take this request: '.$this->getMessage(),
        };
    }
}
