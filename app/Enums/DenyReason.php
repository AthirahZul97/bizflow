<?php

namespace App\Enums;

/**
 * Why an entitlement check refused.
 */
enum DenyReason: string
{
    case ReadOnly = 'read_only';
    case NotIncluded = 'not_included';
    case LimitReached = 'limit_reached';
}
