<?php

namespace App\Enums;

/**
 * What a business may do right now, derived from its current subscription's dates.
 *
 * Full and Grace both allow normal writes (Grace also shows a warning); ReadOnly allows
 * reading existing data and billing actions only.
 */
enum AccessState: string
{
    case Full = 'full';
    case Grace = 'grace';
    case ReadOnly = 'read_only';

    /**
     * Get the human-readable label for the access state.
     */
    public function label(): string
    {
        return match ($this) {
            self::Full => 'Full access',
            self::Grace => 'Grace period',
            self::ReadOnly => 'Read-only',
        };
    }

    public function canWrite(): bool
    {
        return $this !== self::ReadOnly;
    }
}
