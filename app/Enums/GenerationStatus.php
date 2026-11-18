<?php

namespace App\Enums;

enum GenerationStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /** Succeeded and failed are the end. Nothing changes after them. */
    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    /**
     * Position in the life of an image. A status may only move to a higher
     * rank, so a late "processing" webhook can never undo "succeeded".
     */
    public function rank(): int
    {
        return match ($this) {
            self::Queued => 0,
            self::Processing => 1,
            self::Succeeded, self::Failed => 2,
        };
    }

    /** @return list<string> */
    public static function pendingValues(): array
    {
        return [self::Queued->value, self::Processing->value];
    }
}
