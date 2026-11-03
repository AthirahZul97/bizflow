<?php

namespace App\Ocr\Providers;

use App\Ocr\Exceptions\OcrPermanentException;
use App\Ocr\ReceiptDocument;
use App\Ocr\ReceiptExtraction;
use App\Ocr\ReceiptOcrProvider;

/**
 * Bound when OCR_DRIVER=none: receipts already queued fail cleanly instead of crashing.
 */
class DisabledReceiptOcrProvider implements ReceiptOcrProvider
{
    public function name(): string
    {
        return 'none';
    }

    public function extract(ReceiptDocument $document): ReceiptExtraction
    {
        throw new OcrPermanentException('Receipt scanning is switched off.');
    }
}
