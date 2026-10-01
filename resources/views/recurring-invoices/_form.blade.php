@csrf

@php
    // Lines to show: the submitted ones after a validation error, otherwise the saved ones,
    // plus spare blank rows so the form also works without JavaScript (blank rows are ignored).
    $lines = old('items', $recurring->items->map(fn ($item) => [
        'product_id' => $item->product_id,
        'name' => $item->name,
        'description' => $item->description,
        'unit' => $item->unit,
        'quantity' => $item->quantity,
        'unit_price' => $item->unit_price,
    ])->all());
    $lines = array_values(is_array($lines) ? $lines : []);
    $blankRows = max(1, 3 - count($lines));
    $currency = config('bizflow.currency.symbol');
    // Once an invoice has been generated the dates of the schedule are fixed.
    $scheduleLocked = $recurring->exists && $recurring->hasGenerated();
@endphp

@if ($customers->isEmpty())
    <div class="alert alert-warning">
        You need a customer before you can create a recurring invoice.
        <a href="{{ route('customers.create') }}">Add a customer</a>.
    </div>
@endif

@error('items')
    <div class="alert alert-danger">{{ $message }}</div>
@enderror

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <label for="name" class="form-label">Name <span class="text-danger" aria-hidden="true">*</span></label>
        <input id="name" type="text" name="name" value="{{ old('name', $recurring->name) }}" maxlength="255" aria-describedby="nameHelp"
               class="form-control @error('name') is-invalid @enderror" required>
        @error('name')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
        <div id="nameHelp" class="form-text">For your own reference, e.g. “Monthly website maintenance”.</div>
    </div>

    <div class="col-md-6">
        <label for="customer_id" class="form-label">Customer <span class="text-danger" aria-hidden="true">*</span></label>
        <select id="customer_id" name="customer_id" class="form-select @error('customer_id') is-invalid @enderror" required>
            <option value="">Choose a customer</option>
            @foreach ($customers as $customer)
                <option value="{{ $customer->id }}" @selected((string) old('customer_id', $recurring->customer_id) === (string) $customer->id)>
                    {{ $customer->name }}@if ($customer->company_name) &mdash; {{ $customer->company_name }}@endif
                </option>
            @endforeach
        </select>
        @error('customer_id')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
</div>

<h2 class="h6 text-body-secondary text-uppercase mb-2">Schedule</h2>

<div class="row g-3 mb-2">
    <div class="col-6 col-md-3">
        <label for="frequency" class="form-label">Repeats <span class="text-danger" aria-hidden="true">*</span></label>
        <select id="frequency" name="frequency" class="form-select @error('frequency') is-invalid @enderror" required @disabled($scheduleLocked)>
            @foreach (\App\Enums\RecurringFrequency::cases() as $frequency)
                <option value="{{ $frequency->value }}" @selected(old('frequency', $recurring->frequency?->value) === $frequency->value)>{{ $frequency->label() }}</option>
            @endforeach
        </select>
        @error('frequency')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-6 col-md-3">
        <label for="start_date" class="form-label">First invoice date <span class="text-danger" aria-hidden="true">*</span></label>
        <input id="start_date" type="date" name="start_date" value="{{ old('start_date', $recurring->start_date?->toDateString()) }}"
               class="form-control @error('start_date') is-invalid @enderror" required @disabled($scheduleLocked)
               @unless ($scheduleLocked) min="{{ today()->toDateString() }}" @endunless>
        @error('start_date')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-6 col-md-3">
        <label for="end_date" class="form-label">Last possible date</label>
        <input id="end_date" type="date" name="end_date" value="{{ old('end_date', $recurring->end_date?->toDateString()) }}"
               class="form-control @error('end_date') is-invalid @enderror" aria-describedby="endDateHelp">
        @error('end_date')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-6 col-md-3">
        <label for="payment_terms_days" class="form-label">Payment terms <span class="text-danger" aria-hidden="true">*</span></label>
        <div class="input-group has-validation">
            <input id="payment_terms_days" type="number" name="payment_terms_days" min="0" max="365" step="1"
                   value="{{ old('payment_terms_days', $recurring->payment_terms_days) }}"
                   class="form-control @error('payment_terms_days') is-invalid @enderror" required>
            <span class="input-group-text">days</span>
            @error('payment_terms_days')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
    </div>
</div>

<div id="endDateHelp" class="form-text mb-4">
    @if ($scheduleLocked)
        The frequency and first invoice date can’t be changed once an invoice has been generated.
    @else
        Invoices are dated from the first invoice date; a date such as the 31st falls on the last day of shorter months.
    @endif
    Leave the last possible date empty to continue until you cancel; an invoice dated exactly on it is still generated.
    Each invoice is due after the payment terms.
</div>

@include('invoices._lines', [
    'record' => $recurring,
    'totals' => $totals,
    'notesHelp' => 'Copied onto each generated invoice, e.g. payment instructions.',
])
