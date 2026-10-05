<?php

namespace App\Ocr;

/**
 * A receipt file handed to a provider: its bytes and the type the server verified (never the
 * client's claim, and never the client's filename).
 */
final readonly class ReceiptDocument
{
    public function __construct(
        public string $contents,
        public string $mimeType,
        public string $sha256,
    ) {}

    public function size(): int
    {
        return strlen($this->contents);
    }
}
