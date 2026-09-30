<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceEmailStatus;
use App\Http\Requests\SendInvoiceEmailRequest;
use App\Models\Invoice;
use App\Services\InvoiceEmailService;
use App\Support\CurrentBusiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * Emails an invoice to its customer. InvoicePolicy::sendEmail runs first:
 * another business's invoice is 404; a draft or cancelled invoice is 403.
 */
class InvoiceEmailController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly InvoiceEmailService $emails,
        private readonly CurrentBusiness $currentBusiness,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:sendEmail,invoice'),
        ];
    }

    /**
     * Confirm the recipient before sending.
     */
    public function create(Invoice $invoice): View
    {
        $business = $this->currentBusiness->get();
        $invoice->setRelation('business', $business);

        return view('invoices.email', [
            'invoice' => $invoice,
            'options' => $this->emails->recipientOptions($business, $invoice),
            'active' => $invoice->emails()->whereIn('status', [InvoiceEmailStatus::Queued, InvoiceEmailStatus::Sending])->first(),
        ]);
    }

    /**
     * Queue the email. The history row is created now; the email is sent by the queue worker.
     */
    public function store(SendInvoiceEmailRequest $request, Invoice $invoice): RedirectResponse
    {
        $email = $this->emails->queue(
            $this->currentBusiness->get(),
            $invoice,
            $request->user(),
            $request->validated('recipient'),
        );

        return redirect()->route('invoices.show', $invoice)
            ->with('status', "Invoice {$invoice->invoice_number} is queued to be emailed to {$email->recipient_email}.");
    }
}
