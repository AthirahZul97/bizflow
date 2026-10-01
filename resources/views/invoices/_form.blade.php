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

@include('invoices._lines', [
    'record' => $invoice,
    'totals' => $invoice->exists ? [
        'subtotal' => $invoice->money('subtotal'),
        'tax_amount' => $invoice->money('tax_amount'),
        'total' => $invoice->money('total'),
    ] : null,
    'notesHelp' => 'Shown on the invoice, e.g. payment instructions.',
])
