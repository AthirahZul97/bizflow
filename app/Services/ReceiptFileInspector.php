<?php

namespace App\Services;

use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Decides whether an upload really is a receipt BizFlow accepts: a JPEG, PNG or PDF.
 *
 * What the client says (its filename, extension and MIME type) is never trusted. The bytes
 * decide: the type detected from the content (finfo) must be on the allowlist, its magic bytes
 * must match, the file extension must agree with it, an image must really decode to the same
 * type within the pixel limit, and a PDF must look complete and carry no password or active
 * content. The stored name and extension are generated from the detected type later; the
 * client filename is kept only as a cleaned display label.
 *
 * The PDF checks are cheap best-effort screening, not a PDF parser: BizFlow never parses or
 * renders a PDF itself, it only stores it, hands it to the OCR provider and serves it back as
 * a download.
 *
 * @throws ValidationException on any refusal, with a message for the "receipt" field.
 */
class ReceiptFileInspector
{
    /**
     * Detected MIME type => [accepted extensions, canonical extension].
     *
     * @var array<string, array{0: list<string>, 1: string}>
     */
    private const TYPES = [
        'image/jpeg' => [['jpg', 'jpeg'], 'jpg'],
        'image/png' => [['png'], 'png'],
        'application/pdf' => [['pdf'], 'pdf'],
    ];

    private const JPEG_MAGIC = "\xFF\xD8\xFF";

    private const PNG_MAGIC = "\x89PNG\r\n\x1A\n";

    public function inspect(UploadedFile $file): InspectedReceiptFile
    {
        if (! $file->isValid() || $file->getRealPath() === false) {
            $this->refuse('The file could not be uploaded. It may be larger than the server allows.');
        }

        $maxBytes = (int) config('ocr.max_upload_kb') * 1024;

        if (($file->getSize() ?: 0) > $maxBytes) {
            $this->refuse('The file is too large. The limit is '.round($maxBytes / 1048576, 1).' MB.');
        }

        $contents = file_get_contents($file->getRealPath());

        if ($contents === false || $contents === '') {
            $this->refuse('The file is empty.');
        }

        if (strlen($contents) > $maxBytes) {
            $this->refuse('The file is too large. The limit is '.round($maxBytes / 1048576, 1).' MB.');
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: '';

        if (! isset(self::TYPES[$mime])) {
            $this->refuse('Only JPG, PNG and PDF receipts are accepted.');
        }

        $this->checkExtension($file, $mime);
        $this->checkMagicBytes($contents, $mime);

        if ($mime === 'application/pdf') {
            $this->checkPdf($contents);
        } else {
            $this->checkImage($contents, $mime);
        }

        return new InspectedReceiptFile(
            contents: $contents,
            mimeType: $mime,
            extension: self::TYPES[$mime][1],
            sha256: hash('sha256', $contents),
            displayName: $this->displayName($file, self::TYPES[$mime][1]),
        );
    }

    private function checkExtension(UploadedFile $file, string $mime): void
    {
        $extension = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));

        if (! in_array($extension, self::TYPES[$mime][0], true)) {
            $this->refuse('The file name does not match its contents. Upload a JPG, PNG or PDF receipt.');
        }
    }

    private function checkMagicBytes(string $contents, string $mime): void
    {
        $matches = match ($mime) {
            'image/jpeg' => str_starts_with($contents, self::JPEG_MAGIC),
            'image/png' => str_starts_with($contents, self::PNG_MAGIC),
            default => str_starts_with($contents, '%PDF-'),
        };

        if (! $matches) {
            $this->refuse('The file is not a valid receipt image or PDF.');
        }
    }

    private function checkImage(string $contents, string $mime): void
    {
        $info = @getimagesizefromstring($contents);

        if ($info === false || ($info['mime'] ?? null) !== $mime || $info[0] < 1 || $info[1] < 1) {
            $this->refuse('The image is damaged or not a valid JPG or PNG.');
        }

        // Guards against decompression bombs: a tiny file that expands to a huge bitmap.
        if ((int) config('ocr.max_image_pixels') < $info[0] * $info[1]) {
            $this->refuse('The image is too large. Use a smaller photo or scan.');
        }
    }

    private function checkPdf(string $contents): void
    {
        if (! str_contains(substr($contents, -2048), '%%EOF')) {
            $this->refuse('The PDF looks incomplete or damaged.');
        }

        if (str_contains($contents, '/Encrypt')) {
            $this->refuse('Password-protected PDFs are not supported.');
        }

        foreach (['/JavaScript', '/JS', '/Launch', '/EmbeddedFile', '/OpenAction'] as $token) {
            if (str_contains($contents, $token)) {
                $this->refuse('The PDF contains active content and cannot be accepted.');
            }
        }
    }

    /**
     * The uploaded name reduced to something safe to show and to offer as a download name:
     * no directories, no control characters, no quotes, a bounded length. Never used in a path.
     */
    private function displayName(UploadedFile $file, string $extension): string
    {
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $name = preg_replace('/[\p{C}"\'<>:*?|\/\\\\]+/u', '', $name) ?? '';
        $stem = trim(mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 120));

        return ($stem === '' ? 'receipt' : $stem).'.'.$extension;
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['receipt' => $message]);
    }
}
