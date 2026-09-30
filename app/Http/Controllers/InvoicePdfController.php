<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\InvoicePdfService;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Downloads an invoice as a PDF. InvoicePolicy::downloadPdf runs first:
 * another business's invoice is 404 and a draft is 403.
 */
class InvoicePdfController extends Controller implements HasMiddleware
{
    public function __construct(private readonly InvoicePdfService $pdfs) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:downloadPdf,invoice'),
        ];
    }

    public function __invoke(Invoice $invoice): Response
    {
        return response($this->pdfs->render($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->pdfs->filename($invoice).'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
