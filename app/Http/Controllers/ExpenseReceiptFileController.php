<?php

namespace App\Http\Controllers;

use App\Models\ExpenseReceipt;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Symfony\Component\HttpFoundation\Response;

/**
 * The only way to read a receipt file. The files are on a private disk with no public URL, so
 * every read goes through authentication and ExpenseReceiptPolicy::view (another business's
 * receipt is a 404). Images show inline; PDFs are always a download. The headers stop the
 * browser guessing a type, running anything in the file or caching it.
 */
class ExpenseReceiptFileController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:view,expense_receipt'),
        ];
    }

    public function __invoke(Request $request, ExpenseReceipt $expenseReceipt): Response
    {
        if (! $expenseReceipt->hasFile()) {
            abort(404);
        }

        $inline = $expenseReceipt->isImage() && ! $request->boolean('download');

        return $expenseReceipt->disk()->response(
            $expenseReceipt->storage_path,
            $expenseReceipt->original_filename,
            [
                'Content-Type' => $expenseReceipt->mime_type,
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
                'Cache-Control' => 'private, no-store',
                'Referrer-Policy' => 'no-referrer',
            ],
            $inline ? 'inline' : 'attachment',
        );
    }
}
