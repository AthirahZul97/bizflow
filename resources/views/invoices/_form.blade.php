@csrf

@php
    // Lines to show: the submitted ones after a validation error, otherwise the saved ones,
    // plus spare blank rows so the form also works without JavaScript (blank rows are ignored).
    $lines = old('items', $invoice->items->map(fn ($item) => [
        'product_id' => $item->product_id,
        'name' => $item->name,
        'description' => $item->description,
        'unit' => $item->unit,
        'quantity' => $item->quantity,
        'unit_price' => $item->unit_price,
        'line_total' => $item->line_total,
    ])->all());
    $lines = array_values(is_array($lines) ? $lines : []);
    $blankRows = max(1, 3 - count($lines));
    $currency = config('bizflow.currency.symbol');
@endphp

@if ($customers->isEmpty())
    <div class="alert alert-warning">
        You need a customer before you can create an invoice.
        <a href="{{ route('customers.create') }}">Add a customer</a>.
    </div>
@endif

@error('items')
    <div class="alert alert-danger">{{ $message }}</div>
@enderror

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <label for="customer_id" class="form-label">Customer <span class="text-danger" aria-hidden="true">*</span></label>
        <select id="customer_id" name="customer_id" class="form-select @error('customer_id') is-invalid @enderror" required>
            <option value="">Choose a customer</option>
            @foreach ($customers as $customer)
                <option value="{{ $customer->id }}" @selected((string) old('customer_id', $invoice->customer_id) === (string) $customer->id)>
                    {{ $customer->name }}@if ($customer->company_name) &mdash; {{ $customer->company_name }}@endif
                </option>
            @endforeach
        </select>
        @error('customer_id')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-6 col-md-3">
        <label for="issue_date" class="form-label">Issue date <span class="text-danger" aria-hidden="true">*</span></label>
        <input id="issue_date" type="date" name="issue_date" value="{{ old('issue_date', $invoice->issue_date?->toDateString()) }}"
               class="form-control @error('issue_date') is-invalid @enderror" required>
        @error('issue_date')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-6 col-md-3">
        <label for="due_date" class="form-label">Due date <span class="text-danger" aria-hidden="true">*</span></label>
        <input id="due_date" type="date" name="due_date" value="{{ old('due_date', $invoice->due_date?->toDateString()) }}"
               class="form-control @error('due_date') is-invalid @enderror" required>
        @error('due_date')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
</div>

<h2 class="h6 text-body-secondary text-uppercase mb-2">Lines</h2>

<div class="row g-2 small text-body-secondary fw-semibold border-bottom pb-1 d-none d-md-flex">
    <div class="col-md-3">Product</div>
    <div class="col-md-3">Item</div>
    <div class="col-md-1 text-end">Qty</div>
    <div class="col-md-1">Unit</div>
    <div class="col-md-2 text-end">Unit price ({{ $currency }})</div>
    <div class="col-md-1 text-end">Total</div>
    <div class="col-md-1"></div>
</div>

<div data-invoice-lines data-next-index="{{ count($lines) + $blankRows }}">
    @foreach ($lines as $index => $item)
        @include('invoices._item-row', ['index' => $index, 'item' => $item])
    @endforeach
    @for ($i = 0; $i < $blankRows; $i++)
        @include('invoices._item-row', ['index' => count($lines) + $i, 'item' => []])
    @endfor
</div>

<template id="invoice-line-template">
    @include('invoices._item-row', ['index' => '__INDEX__', 'item' => []])
</template>

<button type="button" class="btn btn-sm btn-outline-primary mt-2 mb-4 d-none" data-add-line>+ Add line</button>

<div class="row g-4 mb-4">
    <div class="col-lg-7">
        <label for="notes" class="form-label">Notes</label>
        <textarea id="notes" name="notes" rows="4" maxlength="5000" aria-describedby="notesHelp"
                  class="form-control @error('notes') is-invalid @enderror">{{ old('notes', $invoice->notes) }}</textarea>
        @error('notes')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
        <div id="notesHelp" class="form-text">Shown on the invoice, e.g. payment instructions.</div>
    </div>

    <div class="col-lg-5">
        <div class="card bg-body-tertiary">
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <span>Subtotal</span>
                    <span data-subtotal>{{ $invoice->exists ? $invoice->money('subtotal') : '' }}</span>
                </div>

                <div class="row g-2 align-items-center mb-2">
                    <label for="discount_amount" class="col-5 col-form-label">Discount</label>
                    <div class="col-7">
                        <div class="input-group input-group-sm has-validation">
                            <span class="input-group-text">{{ $currency }}</span>
                            <input id="discount_amount" type="text" inputmode="decimal" name="discount_amount" data-discount
                                   value="{{ old('discount_amount', $invoice->discount_amount ?? '0.00') }}"
                                   class="form-control text-end @error('discount_amount') is-invalid @enderror">
                            @error('discount_amount')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                <div class="row g-2 align-items-center mb-2">
                    <div class="col-5">
                        <label for="tax_label" class="visually-hidden">Tax label</label>
                        <input id="tax_label" type="text" name="tax_label" maxlength="30" placeholder="Tax"
                               value="{{ old('tax_label', $invoice->tax_label) }}"
                               class="form-control form-control-sm @error('tax_label') is-invalid @enderror">
                        @error('tax_label')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-7">
                        <div class="input-group input-group-sm has-validation">
                            <label for="tax_rate" class="visually-hidden">Tax rate</label>
                            <input id="tax_rate" type="text" inputmode="decimal" name="tax_rate" data-tax-rate
                                   value="{{ old('tax_rate', $invoice->tax_rate ?? '0.00') }}"
                                   class="form-control text-end @error('tax_rate') is-invalid @enderror">
                            <span class="input-group-text">%</span>
                            @error('tax_rate')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-between mb-2">
                    <span>Tax amount</span>
                    <span data-tax-amount>{{ $invoice->exists ? $invoice->money('tax_amount') : '' }}</span>
                </div>

                <div class="d-flex justify-content-between border-top pt-2 fw-semibold fs-5">
                    <span>Total</span>
                    <span data-total>{{ $invoice->exists ? $invoice->money('total') : '' }}</span>
                </div>
                <div class="form-text">Totals are calculated when you save.</div>
            </div>
        </div>
    </div>
</div>
