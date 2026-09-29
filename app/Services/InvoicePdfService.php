<?php

namespace App\Services;

use App\Models\Invoice;
use Barryvdh\DomPDF\PDF;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\File;
use LogicException;
use ReflectionClass;

/**
 * Renders an invoice as a PDF. Read-only: it never changes the invoice.
 *
 * Everything shown comes from the details copied onto the invoice and its lines;
 * the only other data read is the seller's name, as on the invoice page.
 * The HTML is our own template with every value escaped, and DomPDF runs with
 * remote access, PHP and JavaScript disabled and file access limited to its fonts.
 */
class InvoicePdfService
{
    /**
     * The HTML handed to DomPDF. Public so tests can check the exact content.
     */
    public function html(Invoice $invoice): string
    {
        $invoice->loadMissing(['items', 'user']);

        return view('invoices.pdf', ['invoice' => $invoice])->render();
    }

    /**
     * The PDF document as bytes.
     */
    public function render(Invoice $invoice): string
    {
        $html = $this->html($invoice);

        $fontDir = dirname((new ReflectionClass(Dompdf::class))->getFileName(), 2).'/lib/fonts';
        $fontCache = storage_path('framework/cache/dompdf');
        File::ensureDirectoryExists($fontCache);

        /** @var PDF $pdf */
        $pdf = app('dompdf.wrapper');
        $pdf->setOption([
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
            'isHtml5ParserEnabled' => true,
            'isFontSubsettingEnabled' => true,
            'defaultFont' => 'DejaVu Sans',
            'fontDir' => $fontDir,
            'fontCache' => $fontCache,
            'chroot' => [$fontDir, $fontCache],
        ]);

        return $pdf->loadHTML($html)->output();
    }

    /**
     * The download filename, built only from the server-generated invoice number.
     */
    public function filename(Invoice $invoice): string
    {
        if ($invoice->invoice_number === null) {
            throw new LogicException('Only numbered (issued) invoices have a PDF.');
        }

        return $invoice->invoice_number.'.pdf';
    }
}
