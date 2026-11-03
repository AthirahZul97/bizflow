<?php

namespace App\Ocr;

/**
 * What a provider read from a receipt, before BizFlow has checked any of it.
 *
 * $fields maps a field name (merchant, receipt_number, date, subtotal, tax, total, currency,
 * payment_method, category, description) to its raw value and, when the provider gives one, a
 * confidence from 0 to 1. A provider with no confidence scores passes null. An unread field is
 * left out or null. $warnings are free-text notes from the provider.
 */
final readonly class ReceiptExtraction
{
    /**
     * @param  array<string, array{value?: mixed, confidence?: float|int|null}>  $fields
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $fields,
        public string $provider,
        public ?string $model = null,
        public array $warnings = [],
    ) {}
}
