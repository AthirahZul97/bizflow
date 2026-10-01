<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceEmailStatus;
use App\Http\Requests\InvoiceRequest;
use App\Models\Invoice;
use App\Services\InvoiceService;
use App\Support\CurrentBusiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Str;
use Illuminate\View\View;

class InvoiceController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly CurrentBusiness $currentBusiness,
    ) {}

    /**
     * Authorize every action through InvoicePolicy.
     *
     * Running as middleware means ownership and draft status are checked before
     * an InvoiceRequest is validated, so another business's invoice returns 404,
     * never validation errors.
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:viewAny,'.Invoice::class, only: ['index']),
            new Middleware('can:create,'.Invoice::class, only: ['create', 'store']),
            new Middleware('can:view,invoice', only: ['show']),
            new Middleware('can:update,invoice', only: ['edit', 'update']),
            new Middleware('can:delete,invoice', only: ['delete', 'destroy']),
        ];
    }

    /**
     * List the current business's invoices, optionally searched and filtered by status.
     */
    public function index(Request $request): View
    {
        $search = $request->query('search');
        $search = is_string($search) ? Str::limit(trim($search), 100, '') : '';

        $status = $request->query('status');
        $status = in_array($status, Invoice::STATUS_FILTERS, true) ? $status : null;

        $invoices = $this->currentBusiness->get()->invoices()
            ->search($search)
            ->when($status, fn ($query) => $query->filterStatus($status))
            // When each invoice was last emailed successfully, in one subquery.
            ->withMax(['emails as last_emailed_at' => fn ($query) => $query->where('status', InvoiceEmailStatus::Sent)], 'sent_at')
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        $filtered = $search !== '' || $status !== null;

        return view('invoices.index', compact('invoices', 'search', 'status', 'filtered'));
    }

    /**
     * Show the form for creating a draft invoice.
     */
    public function create(Request $request): View
    {
        $invoice = new Invoice([
            'issue_date' => today(),
            'due_date' => today()->addDays((int) config('bizflow.invoice.payment_terms_days')),
        ]);

        return view('invoices.create', $this->formData($request, $invoice));
    }

    /**
     * Save a new draft owned by the current business, created by the authenticated user.
     */
    public function store(InvoiceRequest $request): RedirectResponse
    {
        $invoice = $this->invoices->saveDraft($this->currentBusiness->get(), $request->user(), $request->validated());

        return redirect()->route('invoices.show', $invoice)
            ->with('status', 'Draft invoice saved.');
    }

    /**
     * Show an invoice.
     */
    public function show(Invoice $invoice): View
    {
        $invoice->load([
            'items',
            'business',
            'recurringInvoice:id,name',
            'emails' => fn ($query) => $query->with('requester:id,name')->latest('id'),
        ]);

        return view('invoices.show', compact('invoice'));
    }

    /**
     * Show the form for editing a draft.
     */
    public function edit(Request $request, Invoice $invoice): View
    {
        $invoice->load('items');

        return view('invoices.edit', $this->formData($request, $invoice));
    }

    /**
     * Update a draft.
     */
    public function update(InvoiceRequest $request, Invoice $invoice): RedirectResponse
    {
        $invoice = $this->invoices->saveDraft($this->currentBusiness->get(), $request->user(), $request->validated(), $invoice);

        return redirect()->route('invoices.show', $invoice)
            ->with('status', 'Draft invoice updated.');
    }

    /**
     * Ask for confirmation before deleting a draft. This action never deletes.
     */
    public function delete(Invoice $invoice): View
    {
        return view('invoices.delete', compact('invoice'));
    }

    /**
     * Permanently delete a draft. Issued invoices are cancelled, never deleted.
     */
    public function destroy(Invoice $invoice): RedirectResponse
    {
        $this->invoices->deleteDraft($invoice);

        return redirect()->route('invoices.index')
            ->with('status', 'Draft invoice deleted.');
    }

    /**
     * The business's customers, and the products a line may use: active ones plus
     * any inactive ones this draft already uses.
     *
     * @return array<string, mixed>
     */
    private function formData(Request $request, Invoice $invoice): array
    {
        $business = $this->currentBusiness->get();
        $usedProductIds = $invoice->exists
            ? $invoice->items->pluck('product_id')->filter()->all()
            : [];

        return [
            'invoice' => $invoice,
            'customers' => $business->customers()->orderBy('name')->get(),
            'products' => $business->products()
                ->where(fn ($query) => $query->active()->orWhereIn('id', $usedProductIds))
                ->orderBy('name')
                ->get(),
        ];
    }
}
