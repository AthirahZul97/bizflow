<?php

namespace App\Enums;

enum ProductType: string
{
    case Product = 'product';
    case Service = 'service';

    /**
     * Get the human-readable label for the type.
     */
    public function label(): string
    {
        return match ($this) {
            self::Product => 'Product',
            self::Service => 'Service',
        };
    }
}
