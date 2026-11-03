<?php

namespace App\Services;

/**
 * An uploaded receipt that passed ReceiptFileInspector: its bytes, the type the server
 * detected, the extension that type implies, the SHA-256 of the bytes and a display-safe name.
 */
final readonly class InspectedReceiptFile
{
    public function __construct(
        public string $contents,
        public string $mimeType,
        public string $extension,
        public string $sha256,
        public string $displayName,
    ) {}

    public function size(): int
    {
        return strlen($this->contents);
    }
}
