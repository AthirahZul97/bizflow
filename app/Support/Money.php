<?php

namespace App\Support;

class Money
{
    /**
     * Format an exact decimal string for display, e.g. "1500.5" as "RM 1,500.50".
     *
     * Works on the string so no floating point is involved.
     */
    public static function format(string $amount): string
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return config('bizflow.currency.symbol').' '.number_format((int) $whole).'.'.str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
