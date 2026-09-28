<?php

namespace App\Http\Controllers;

use App\Http\Requests\MarkInvoicePaidRequest;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * Invoice lifecycle actions. Each has its own route and policy ability;
 * a status is never accepted from form input.
 */
class InvoiceStatusController extends Controller implements HasMiddleware
{
    public function __construct(private readonly InvoiceService $invoices) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:issue,invoice', only: ['issue']),
            new Middleware('can:markPaid,invoice', only: ['markPaid']),
            new Middleware('can:markUnpaid,invoice', only: ['markUnpaid']),
            new Middleware('can:cancel,invoice', only: ['confirmCancel', 'cancel']),
        ];
    }

    /**
     * Issue a draft, giving it the next invoice number.
     */
    public function issue(Invoice $invoice): RedirectResponse
    {
        $invoice = $this->invoices->issue($invoice);

        return redirect()->route('invoices.show', $invoice)
            ->with('status', "Invoice {$invoice->invoice_number} issued.");
    }

    /**
     * Mark an issued invoice as paid in full on the given date.
     */
    public function markPaid(MarkInvoicePaidRequest $request, Invoice $invoice): RedirectResponse
    {
        $invoice = $this->invoices->markPaid($invoice, $request->validated('paid_at'));

        return redirect()->route('invoices.show', $invoice)
            ->with('status', "Invoice {$invoice->invoice_number} marked as paid.");
    }

    /**
     * Undo a "paid" mark.
     */
    public function markUnpaid(Invoice $invoice): RedirectResponse
    {
        $invoice = $this->invoices->markUnpaid($invoice);

        return redirect()->route('invoices.show', $invoice)
            ->with('status', "Invoice {$invoice->invoice_number} marked as unpaid.");
    }

    /**
     * Ask for confirmation before cancelling. This action never cancels.
     */
    public function confirmCancel(Invoice $invoice): View
    {
        return view('invoices.cancel', compact('invoice'));
    }

    /**
     * Cancel an issued invoice. It keeps its number and stays on record.
     */
    public function cancel(Invoice $invoice): RedirectResponse
    {
        $invoice = $this->invoices->cancel($invoice);

        return redirect()->route('invoices.show', $invoice)
            ->with('status', "Invoice {$invoice->invoice_number} cancelled.");
    }
}
