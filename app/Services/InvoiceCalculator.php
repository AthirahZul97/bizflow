<?php

namespace App\Services;

use App\Exceptions\InvoiceCalculationException;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

/**
 * Pure invoice arithmetic using exact decimals. No database access and no floats.
 *
 *   line_total = round_half_up(quantity × unit_price, 2)
 *   subtotal   = Σ line_total
 *   taxable    = subtotal − discount
 *   tax_amount = round_half_up(taxable × tax_rate ÷ 100, 2)
 *   total      = taxable + tax_amount
 */
class InvoiceCalculator
{
    /**
     * Largest value a DECIMAL(15,2) column can hold.
     */
    public const MAX_AMOUNT = '9999999999999.99';

    /**
     * Calculate an invoice's totals.
     *
     * @param  list<array{quantity: string, unit_price: string}>  $lines
     *
     * @throws InvoiceCalculationException when an input is invalid or a result would not fit the database
     */
    public function calculate(array $lines, ?string $discount = '0', ?string $taxRate = '0'): InvoiceTotals
    {
        $max = BigDecimal::of(self::MAX_AMOUNT);
        $lineTotals = [];
        $subtotal = BigDecimal::zero();

        foreach (array_values($lines) as $index => $line) {
            $quantity = $this->decimal($line['quantity'], "items.{$index}.quantity");
            $unitPrice = $this->decimal($line['unit_price'], "items.{$index}.unit_price");

            if ($quantity->isLessThanOrEqualTo(0)) {
                throw new InvoiceCalculationException('The quantity must be greater than zero.', "items.{$index}.quantity");
            }

            if ($unitPrice->isNegative()) {
                throw new InvoiceCalculationException('The unit price cannot be negative.', "items.{$index}.unit_price");
            }

            $lineTotal = $quantity->multipliedBy($unitPrice)->toScale(2, RoundingMode::HALF_UP);

            if ($lineTotal->isGreaterThan($max)) {
                throw new InvoiceCalculationException('This line total is too large.', "items.{$index}.unit_price");
            }

            $lineTotals[] = (string) $lineTotal;
            $subtotal = $subtotal->plus($lineTotal);
        }

        if ($subtotal->isGreaterThan($max)) {
            throw new InvoiceCalculationException('The invoice subtotal is too large.', 'items');
        }

        $discountAmount = $this->decimal($discount ?? '0', 'discount_amount')->toScale(2);

        if ($discountAmount->isNegative()) {
            throw new InvoiceCalculationException('The discount cannot be negative.', 'discount_amount');
        }

        if ($discountAmount->isGreaterThan($subtotal)) {
            throw new InvoiceCalculationException('The discount cannot be more than the subtotal.', 'discount_amount');
        }

        $rate = $this->decimal($taxRate ?? '0', 'tax_rate');

        if ($rate->isNegative() || $rate->isGreaterThan(100)) {
            throw new InvoiceCalculationException('The tax rate must be between 0 and 100.', 'tax_rate');
        }

        $taxable = $subtotal->minus($discountAmount);
        $taxAmount = $taxable->multipliedBy($rate)->dividedBy(100, 2, RoundingMode::HALF_UP);
        $total = $taxable->plus($taxAmount);

        if ($total->isGreaterThan($max)) {
            throw new InvoiceCalculationException('The invoice total is too large.', 'items');
        }

        return new InvoiceTotals(
            lineTotals: $lineTotals,
            subtotal: (string) $subtotal->toScale(2),
            discountAmount: (string) $discountAmount,
            taxAmount: (string) $taxAmount,
            total: (string) $total->toScale(2),
        );
    }

    /**
     * Parse a plain decimal string. Anything else (floats, exponents, separators) is rejected.
     */
    private function decimal(mixed $value, string $field): BigDecimal
    {
        if (! is_string($value) || preg_match('/^-?\d+(\.\d+)?$/', $value) !== 1) {
            throw new InvoiceCalculationException('The amount must be a plain decimal number.', $field);
        }

        try {
            return BigDecimal::of($value);
        } catch (MathException) {
            throw new InvoiceCalculationException('The amount must be a plain decimal number.', $field);
        }
    }
}
