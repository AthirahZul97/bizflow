<?php

namespace App\Ocr\Providers;

use App\Ocr\ReceiptDocument;
use App\Ocr\ReceiptExtraction;
use App\Ocr\ReceiptOcrProvider;

/**
 * DEMO PROVIDER. It reads nothing from the receipt: every document gets the same made-up
 * merchant, date and amounts (only the receipt number is derived from the file's hash), so
 * development and tests are deterministic. It is not OCR and must never be mistaken for it;
 * the UI says so wherever its output appears. No real provider has been selected.
 */
class FakeReceiptOcrProvider implements ReceiptOcrProvider
{
    public const NAME = 'fake';

    public const MODEL = 'fake-1';

    public function name(): string
    {
        return self::NAME;
    }

    public function extract(ReceiptDocument $document): ReceiptExtraction
    {
        return new ReceiptExtraction(
            fields: [
                'merchant' => ['value' => 'Fake Merchant Sdn Bhd', 'confidence' => 0.99],
                'receipt_number' => ['value' => 'FAKE-'.strtoupper(substr($document->sha256, 0, 8)), 'confidence' => 0.9],
                'date' => ['value' => '2026-01-15', 'confidence' => 0.95],
                'subtotal' => ['value' => '40.09', 'confidence' => 0.9],
                'tax' => ['value' => '2.41', 'confidence' => 0.9],
                'total' => ['value' => '42.50', 'confidence' => 0.97],
                'currency' => ['value' => 'MYR', 'confidence' => 0.99],
                'payment_method' => ['value' => 'Cash', 'confidence' => 0.5],
                'category' => ['value' => 'office', 'confidence' => 0.4],
            ],
            provider: self::NAME,
            model: self::MODEL,
        );
    }
}
