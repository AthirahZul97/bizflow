<?php

namespace App\Http\Controllers;

use App\Billing\EntitlementGuard;
use App\Enums\Entitlement;
use App\Http\Requests\CustomerRequest;
use App\Models\Customer;
use App\Support\CurrentBusiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CustomerController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly CurrentBusiness $currentBusiness,
        private readonly EntitlementGuard $guard,
    ) {}

    /**
     * Authorize every action through CustomerPolicy.
     *
     * Running as middleware means ownership is checked before a CustomerRequest
     * is validated, so another business's customer returns 404, never validation errors.
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:viewAny,'.Customer::class, only: ['index']),
            new Middleware('can:create,'.Customer::class, only: ['create', 'store']),
            new Middleware('can:view,customer', only: ['show']),
            new Middleware('can:update,customer', only: ['edit', 'update']),
            new Middleware('can:delete,customer', only: ['delete', 'destroy']),
        ];
    }

    /**
     * List the current business's customers, optionally filtered by a search term.
     */
    public function index(Request $request): View
    {
        $search = $request->query('search');
        $search = is_string($search) ? Str::limit(trim($search), 100, '') : '';

        $customers = $this->currentBusiness->get()->customers()
            ->search($search)
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        return view('customers.index', compact('customers', 'search'));
    }

    /**
     * Show the form for creating a customer.
     */
    public function create(): View
    {
        return view('customers.create', ['customer' => new Customer]);
    }

    /**
     * Store a customer owned by the current business.
     */
    public function store(CustomerRequest $request): RedirectResponse
    {
        $business = $this->currentBusiness->get();

        // Counted and inserted under the business row lock, so two requests at the plan's
        // limit can't both succeed.
        $customer = $this->guard->create(
            $business,
            Entitlement::Customers,
            fn () => $business->customers()->create($request->validated()),
        );

        return redirect()->route('customers.show', $customer)
            ->with('status', 'Customer created.');
    }

    /**
     * Show a customer.
     */
    public function show(Customer $customer): View
    {
        return view('customers.show', compact('customer'));
    }

    /**
     * Show the form for editing a customer.
     */
    public function edit(Customer $customer): View
    {
        return view('customers.edit', compact('customer'));
    }

    /**
     * Update a customer.
     */
    public function update(CustomerRequest $request, Customer $customer): RedirectResponse
    {
        $customer->update($request->validated());

        return redirect()->route('customers.show', $customer)
            ->with('status', 'Customer updated.');
    }

    /**
     * Ask for confirmation before deleting a customer. This action never deletes.
     */
    public function delete(Customer $customer): View
    {
        return view('customers.delete', [
            'customer' => $customer,
            'hasInvoices' => $customer->invoices()->exists(),
            'hasRecurringInvoices' => $customer->recurringInvoices()->exists(),
        ]);
    }

    /**
     * Permanently delete a customer.
     *
     * This is the single place customers are deleted. A customer with invoices is
     * kept, backed by the restrictOnDelete foreign key on invoices.customer_id; so is
     * one billed by a recurring invoice (recurring_invoices has the same foreign key).
     */
    public function destroy(Customer $customer): RedirectResponse
    {
        if ($customer->invoices()->exists()) {
            return redirect()->route('customers.show', $customer)
                ->with('error', 'This customer has invoices and cannot be deleted.');
        }

        if ($customer->recurringInvoices()->exists()) {
            return redirect()->route('customers.show', $customer)
                ->with('error', 'This customer has a recurring invoice and cannot be deleted.');
        }

        $customer->delete();

        return redirect()->route('customers.index')
            ->with('status', 'Customer deleted.');
    }
}
