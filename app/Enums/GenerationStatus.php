<?php

namespace App\Enums;

enum GenerationStatus: string
{
    // Ours only: saved locally, not yet accepted by Pixelforge.
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    /**
     * Position in the lifecycle, used to ignore events that would move a
     * generation backwards when webhooks arrive out of order.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }

    /**
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::Pending, self::Queued, self::Processing];
    }
}
