<?php

namespace App\Ocr;

use App\Ocr\Exceptions\OcrMalformedResponseException;
use App\Ocr\Exceptions\OcrPermanentException;
use App\Ocr\Exceptions\OcrTransientException;

/**
 * Reads a receipt. The one seam between BizFlow and any OCR service: nothing else in the
 * application knows which provider is in use, so one can be swapped for another by binding a
 * different implementation (see AppServiceProvider and config/ocr.php).
 *
 * An implementation returns what the receipt says, without judging it: normalizing and
 * validating the values is ReceiptExtractionNormalizer's job, so every provider gets the same
 * checks. It must never log the document or what was read from it, and must not keep a copy of
 * the response; the caller stores only the normalized fields.
 */
interface ReceiptOcrProvider
{
    /**
     * A short stable identifier stored on the receipt, e.g. "fake".
     */
    public function name(): string;

    /**
     * @throws OcrTransientException when trying again later may work (timeout, rate limit, outage)
     * @throws OcrPermanentException when trying again cannot work (rejected file, bad credentials)
     * @throws OcrMalformedResponseException when the provider answered with something unusable
     */
    public function extract(ReceiptDocument $document): ReceiptExtraction;
}
