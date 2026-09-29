<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class Money
{
    /**
     * Format an exact decimal string for display, e.g. "1500.5" as "RM 1,500.50"
     * and "-0.5" as "-RM 0.50".
     *
     * Works on the string so no floating point is involved. The sign is handled
     * separately because casting a whole part of "-0" to int would drop it.
     */
    public static function format(string $amount): string
    {
        $negative = str_starts_with($amount, '-');
        $unsigned = $negative ? substr($amount, 1) : $amount;

        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');

        $formatted = config('bizflow.currency.symbol').' '.number_format((int) $whole).'.'.$fraction;

        // Never show a minus sign on zero ("-0.00").
        $isZero = trim($whole.$fraction, '0') === '';

        return $negative && ! $isZero ? '-'.$formatted : $formatted;
    }

    /**
     * Normalise a SQL aggregate (e.g. SUM) to an exact two-decimal string.
     *
     * MySQL returns an exact decimal string; SQLite may return a float or an
     * integer. The value is converted to a string before BigDecimal, then rounded
     * once, half up, to 2 decimal places. NULL (no rows) becomes "0.00".
     */
    public static function fromSql(mixed $value): string
    {
        return (string) BigDecimal::of((string) ($value ?? '0'))->toScale(2, RoundingMode::HALF_UP);
    }
}
