<?php

namespace App\Ocr;

/**
 * A receipt extraction after normalization: clean values, confidences and warnings, in the
 * shape stored in expense_receipts.extraction.
 */
final readonly class NormalizedReceipt
{
    /**
     * @param  array<string, array{value: string|null, confidence: float|null}>  $fields
     * @param  list<array{code: string, field: string|null, message: string}>  $warnings
     */
    public function __construct(
        public array $fields,
        public array $warnings,
        public string $provider,
        public ?string $model,
    ) {}

    /**
     * Usable means there is a valid total: without one the user has nothing to review.
     */
    public function isUsable(): bool
    {
        return ($this->fields['total']['value'] ?? null) !== null;
    }

    /**
     * @return array{fields: array<string, array{value: string|null, confidence: float|null}>, warnings: list<array{code: string, field: string|null, message: string}>, manual: bool}
     */
    public function toArray(): array
    {
        return ['fields' => $this->fields, 'warnings' => $this->warnings, 'manual' => false];
    }
}
