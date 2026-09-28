<?php

namespace App\Services;

/**
 * The result of an invoice calculation. Every amount is an exact two-decimal string.
 */
final readonly class InvoiceTotals
{
    /**
     * @param  list<string>  $lineTotals  One total per line, in line order.
     */
    public function __construct(
        public array $lineTotals,
        public string $subtotal,
        public string $discountAmount,
        public string $taxAmount,
        public string $total,
    ) {}
}
