<?php

namespace App\Enums;

/**
 * A member's role in a business. Only the owner exists for now; further roles
 * become new cases here.
 */
enum BusinessRole: string
{
    case Owner = 'owner';

    /**
     * Get the human-readable label for the role.
     */
    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
        };
    }
}
