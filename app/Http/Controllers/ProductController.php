<?php

namespace App\Http\Controllers;

use App\Enums\ProductType;
use App\Http\Requests\ProductRequest;
use App\Models\Product;
use App\Support\CurrentBusiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ProductController extends Controller implements HasMiddleware
{
    public function __construct(private readonly CurrentBusiness $currentBusiness) {}

    /**
     * Authorize every action through ProductPolicy.
     *
     * Running as middleware means ownership is checked before a ProductRequest
     * is validated, so another business's item returns 404, never validation errors.
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:viewAny,'.Product::class, only: ['index']),
            new Middleware('can:create,'.Product::class, only: ['create', 'store']),
            new Middleware('can:view,product', only: ['show']),
            new Middleware('can:update,product', only: ['edit', 'update']),
            new Middleware('can:delete,product', only: ['delete', 'destroy']),
        ];
    }

    /**
     * List the current business's products and services, with optional search and filters.
     */
    public function index(Request $request): View
    {
        $search = $request->query('search');
        $search = is_string($search) ? Str::limit(trim($search), 100, '') : '';

        $type = $request->query('type');
        $type = is_string($type) ? ProductType::tryFrom($type) : null;

        $status = $request->query('status');
        $status = in_array($status, ['active', 'inactive'], true) ? $status : null;

        $products = $this->currentBusiness->get()->products()
            ->search($search)
            ->when($type, fn ($query) => $query->ofType($type))
            ->when($status, fn ($query) => $query->active($status === 'active'))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        $filtered = $search !== '' || $type !== null || $status !== null;

        return view('products.index', compact('products', 'search', 'type', 'status', 'filtered'));
    }

    /**
     * Show the form for creating a product or service.
     */
    public function create(): View
    {
        return view('products.create', ['product' => new Product]);
    }

    /**
     * Store an item owned by the current business.
     */
    public function store(ProductRequest $request): RedirectResponse
    {
        $product = $this->currentBusiness->get()->products()->create($request->validated());

        return redirect()->route('products.show', $product)
            ->with('status', $product->type->label().' created.');
    }

    /**
     * Show a product or service.
     */
    public function show(Product $product): View
    {
        return view('products.show', compact('product'));
    }

    /**
     * Show the form for editing a product or service.
     */
    public function edit(Product $product): View
    {
        return view('products.edit', compact('product'));
    }

    /**
     * Update a product or service.
     */
    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $product->update($request->validated());

        return redirect()->route('products.show', $product)
            ->with('status', $product->type->label().' updated.');
    }

    /**
     * Ask for confirmation before deleting an item. This action never deletes.
     */
    public function delete(Product $product): View
    {
        return view('products.delete', [
            'product' => $product,
            'usedOnInvoices' => $product->invoiceItems()->exists(),
        ]);
    }

    /**
     * Permanently delete a product or service.
     *
     * This is the single place items are deleted. An item used on invoices is kept,
     * backed by the restrictOnDelete foreign key on invoice_items.product_id.
     */
    public function destroy(Product $product): RedirectResponse
    {
        if ($product->invoiceItems()->exists()) {
            return redirect()->route('products.show', $product)
                ->with('error', 'This item is used on invoices and cannot be deleted. Mark it inactive instead.');
        }

        $product->delete();

        return redirect()->route('products.index')
            ->with('status', $product->type->label().' deleted.');
    }
}
