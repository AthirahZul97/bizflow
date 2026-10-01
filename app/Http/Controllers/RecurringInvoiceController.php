<?php

namespace App\Http\Controllers;

use App\Enums\RecurringFrequency;
use App\Http\Requests\RecurringInvoiceRequest;
use App\Models\RecurringInvoice;
use App\Services\RecurringInvoiceService;
use App\Support\CurrentBusiness;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

class RecurringInvoiceController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly RecurringInvoiceService $recurringInvoices,
        private readonly CurrentBusiness $currentBusiness,
    ) {}

    /**
     * Authorize every action through RecurringInvoicePolicy.
     *
     * Running as middleware means ownership and state are checked before a
     * RecurringInvoiceRequest is validated, so another business's recurring
     * invoice returns 404, never validation errors.
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:viewAny,'.RecurringInvoice::class, only: ['index']),
            new Middleware('can:create,'.RecurringInvoice::class, only: ['create', 'store']),
            new Middleware('can:view,recurring_invoice', only: ['show']),
            new Middleware('can:update,recurring_invoice', only: ['edit', 'update']),
            new Middleware('can:delete,recurring_invoice', only: ['delete', 'destroy']),
        ];
    }

    /**
     * List the current business's recurring invoices.
     */
    public function index(): View
    {
        $recurringInvoices = $this->currentBusiness->get()->recurringInvoices()
            ->with(['customer:id,name,company_name', 'items'])
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15);

        $totals = $recurringInvoices->getCollection()
            ->mapWithKeys(fn (RecurringInvoice $recurring) => [$recurring->id => $this->recurringInvoices->totals($recurring)]);

        return view('recurring-invoices.index', [
            'recurringInvoices' => $recurringInvoices,
            'totals' => $totals,
            'today' => today(),
        ]);
    }

    public function create(): View
    {
        $recurring = new RecurringInvoice([
            'payment_terms_days' => (int) config('bizflow.invoice.payment_terms_days'),
        ]);
        $recurring->forceFill([
            'frequency' => RecurringFrequency::Monthly,
            'start_date' => today(),
        ]);

        return view('recurring-invoices.create', $this->formData($recurring));
    }

    /**
     * Save a new recurring invoice owned by the current business.
     */
    public function store(RecurringInvoiceRequest $request): RedirectResponse
    {
        $recurring = $this->recurringInvoices->save($this->currentBusiness->get(), $request->user(), $request->validated());

        return redirect()->route('recurring-invoices.show', $recurring)
            ->with('status', 'Recurring invoice created.');
    }

    public function show(RecurringInvoice $recurringInvoice): View
    {
        $recurringInvoice->load(['customer', 'items']);

        return view('recurring-invoices.show', [
            'recurring' => $recurringInvoice,
            'totals' => $this->recurringInvoices->totals($recurringInvoice),
            'invoices' => $recurringInvoice->invoices()->orderByDesc('recurring_occurrence_on')->orderByDesc('id')->paginate(12),
            'today' => today(),
        ]);
    }

    public function edit(RecurringInvoice $recurringInvoice): View
    {
        $recurringInvoice->load('items');

        return view('recurring-invoices.edit', $this->formData($recurringInvoice));
    }

    /**
     * Update the template. Invoices it already generated are never changed.
     */
    public function update(RecurringInvoiceRequest $request, RecurringInvoice $recurringInvoice): RedirectResponse
    {
        $recurring = $this->recurringInvoices->save(
            $this->currentBusiness->get(), $request->user(), $request->validated(), $recurringInvoice,
        );

        return redirect()->route('recurring-invoices.show', $recurring)
            ->with('status', 'Recurring invoice updated. Invoices already generated are unchanged.');
    }

    /**
     * Confirm deleting a recurring invoice that has never generated an invoice.
     */
    public function delete(RecurringInvoice $recurringInvoice): View
    {
        return view('recurring-invoices.delete', ['recurring' => $recurringInvoice]);
    }

    public function destroy(RecurringInvoice $recurringInvoice): RedirectResponse
    {
        $this->recurringInvoices->delete($this->currentBusiness->get(), $recurringInvoice);

        return redirect()->route('recurring-invoices.index')
            ->with('status', 'Recurring invoice deleted.');
    }

    /**
     * The business's customers, and the active products a line may use.
     *
     * @return array<string, mixed>
     */
    private function formData(RecurringInvoice $recurring): array
    {
        $business = $this->currentBusiness->get();
        $totals = $recurring->exists ? $this->recurringInvoices->totals($recurring) : null;

        return [
            'recurring' => $recurring,
            'totals' => $totals === null ? null : [
                'subtotal' => Money::format($totals->subtotal),
                'tax_amount' => Money::format($totals->taxAmount),
                'total' => Money::format($totals->total),
            ],
            'customers' => $business->customers()->orderBy('name')->get(),
            'products' => $business->products()->active()->orderBy('name')->get(),
        ];
    }
}
