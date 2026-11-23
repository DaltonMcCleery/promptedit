<?php

namespace App\Enums;

enum GenerationStatus: string
{
    /** The app's own state until Pixelforge has accepted the request. */
    case Submitting = 'submitting';

    case Queued = 'queued';

    case Processing = 'processing';

    case Succeeded = 'succeeded';

    case Failed = 'failed';

    /**
     * Status only moves to a higher rank; succeeded and failed share the top rank, so neither can follow the other.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Submitting => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }

    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }
}
