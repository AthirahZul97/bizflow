<?php

namespace App\Http\Controllers;

use App\Models\RecurringInvoice;
use App\Services\RecurringInvoiceService;
use App\Support\CurrentBusiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * Pause, resume, cancel and "Generate now". RecurringInvoicePolicy runs first:
 * another business's recurring invoice is 404, the wrong state is 403.
 */
class RecurringInvoiceStatusController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly RecurringInvoiceService $recurringInvoices,
        private readonly CurrentBusiness $currentBusiness,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:pause,recurring_invoice', only: ['pause']),
            new Middleware('can:resume,recurring_invoice', only: ['resume']),
            new Middleware('can:cancel,recurring_invoice', only: ['confirmCancel', 'cancel']),
            new Middleware('can:generate,recurring_invoice', only: ['generate']),
        ];
    }

    public function pause(RecurringInvoice $recurringInvoice): RedirectResponse
    {
        $this->recurringInvoices->pause($this->currentBusiness->get(), $recurringInvoice);

        return redirect()->route('recurring-invoices.show', $recurringInvoice)
            ->with('status', 'Recurring invoice paused. No invoices are generated while it is paused.');
    }

    public function resume(RecurringInvoice $recurringInvoice): RedirectResponse
    {
        $recurring = $this->recurringInvoices->resume($this->currentBusiness->get(), $recurringInvoice);

        return redirect()->route('recurring-invoices.show', $recurring)
            ->with('status', 'Recurring invoice resumed. The next invoice is for '.$recurring->next_occurrence_on->format('d M Y').'.');
    }

    public function confirmCancel(RecurringInvoice $recurringInvoice): View
    {
        return view('recurring-invoices.cancel', ['recurring' => $recurringInvoice]);
    }

    public function cancel(RecurringInvoice $recurringInvoice): RedirectResponse
    {
        $this->recurringInvoices->cancel($this->currentBusiness->get(), $recurringInvoice);

        return redirect()->route('recurring-invoices.show', $recurringInvoice)
            ->with('status', 'Recurring invoice cancelled. Invoices already generated are unchanged.');
    }

    /**
     * Generate the next occurrence, if it is due today or earlier, as a draft invoice.
     */
    public function generate(Request $request, RecurringInvoice $recurringInvoice): RedirectResponse
    {
        $invoice = $this->recurringInvoices->generateNow($this->currentBusiness->get(), $recurringInvoice, $request->user());

        return redirect()->route('invoices.show', $invoice)
            ->with('status', 'Draft invoice generated for '.$invoice->recurring_occurrence_on->format('d M Y').'.');
    }
}
