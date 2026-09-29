<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * The single place that builds the database-specific "Y-m" month expression
 * used to group rows by calendar month.
 *
 * Column names must be internal constants, never request input; anything that
 * is not a plain column identifier is rejected.
 */
final class SqlMonth
{
    public static function expression(string $driver, string $column): string
    {
        if (preg_match('/^[a-z_]+$/', $column) !== 1) {
            throw new InvalidArgumentException('SqlMonth only accepts plain column names.');
        }

        return match ($driver) {
            'sqlite' => "strftime('%Y-%m', {$column})",
            default => "DATE_FORMAT({$column}, '%Y-%m')",
        };
    }
}
