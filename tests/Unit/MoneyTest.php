<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MoneyTest extends TestCase
{
    /**
     * The formatter as it was before negative support was added, kept here only
     * to prove non-negative output is unchanged.
     */
    private function previousFormat(string $amount): string
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return config('bizflow.currency.symbol').' '.number_format((int) $whole).'.'.str_pad(substr($fraction, 0, 2), 2, '0');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function nonNegativeAmounts(): array
    {
        return [
            'zero' => ['0', 'RM 0.00'],
            'zero with decimals' => ['0.00', 'RM 0.00'],
            'one cent' => ['0.01', 'RM 0.01'],
            'fifty cents' => ['0.50', 'RM 0.50'],
            'whole number' => ['1500', 'RM 1,500.00'],
            'one decimal' => ['1500.5', 'RM 1,500.50'],
            'two decimals' => ['1500.55', 'RM 1,500.55'],
            'millions' => ['1234567.89', 'RM 1,234,567.89'],
            'invoice/expense maximum' => ['9999999999999.99', 'RM 9,999,999,999,999.99'],
            'sum above a column maximum' => ['19999999999999.98', 'RM 19,999,999,999,999.98'],
        ];
    }

    #[DataProvider('nonNegativeAmounts')]
    public function test_non_negative_output_is_unchanged(string $amount, string $expected): void
    {
        $this->assertSame($expected, Money::format($amount));
        $this->assertSame($this->previousFormat($amount), Money::format($amount));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function negativeAmounts(): array
    {
        return [
            'fifty cents (the reported bug)' => ['-0.50', '-RM 0.50'],
            'one cent' => ['-0.01', '-RM 0.01'],
            'ninety-nine cents' => ['-0.99', '-RM 0.99'],
            'one ringgit' => ['-1.00', '-RM 1.00'],
            'thousands' => ['-1234.50', '-RM 1,234.50'],
            'one decimal' => ['-1500.5', '-RM 1,500.50'],
            'large negative' => ['-9999999999999.99', '-RM 9,999,999,999,999.99'],
            'larger than a column maximum' => ['-19999999999999.98', '-RM 19,999,999,999,999.98'],
        ];
    }

    #[DataProvider('negativeAmounts')]
    public function test_negative_amounts_keep_their_sign(string $amount, string $expected): void
    {
        $this->assertSame($expected, Money::format($amount));
    }

    public function test_the_previous_formatter_really_lost_the_sign(): void
    {
        $this->assertSame('RM 0.50', $this->previousFormat('-0.50'));
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function sqlValues(): array
    {
        return [
            'null (no rows)' => [null, '0.00'],
            'integer zero' => [0, '0.00'],
            'mysql exact string' => ['19999999999999.98', '19999999999999.98'],
            'short string' => ['1500.5', '1500.50'],
            'sqlite float noise' => [0.1 + 0.2, '0.30'],
            'sqlite float' => [1500.5, '1500.50'],
            'negative' => ['-0.5', '-0.50'],
            'half up' => ['0.005', '0.01'],
        ];
    }

    #[DataProvider('sqlValues')]
    public function test_sql_aggregates_are_normalised_to_exact_two_decimal_strings(mixed $value, string $expected): void
    {
        $this->assertSame($expected, Money::fromSql($value));
    }

    public function test_negative_zero_is_shown_without_a_sign(): void
    {
        $this->assertSame('RM 0.00', Money::format('-0.00'));
        $this->assertSame('RM 0.00', Money::format('-0'));
    }
}
