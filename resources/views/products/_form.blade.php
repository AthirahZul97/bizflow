@csrf

@php
    $selectedType = old('type', $product->type?->value ?? \App\Enums\ProductType::Product->value);
    $currency = config('bizflow.currency.symbol');
@endphp

<h2 class="h6 text-body-secondary text-uppercase mb-3">Type</h2>

<div class="mb-4">
    <div class="btn-group" role="group" aria-label="Item type">
        @foreach (\App\Enums\ProductType::cases() as $case)
            <input type="radio" class="btn-check" name="type" id="type_{{ $case->value }}" value="{{ $case->value }}"
                   autocomplete="off" @checked($selectedType === $case->value)>
            <label class="btn btn-outline-primary" for="type_{{ $case->value }}">{{ $case->label() }}</label>
        @endforeach
    </div>
    @error('type')
        <div class="invalid-feedback d-block">{{ $message }}</div>
    @enderror
</div>

<h2 class="h6 text-body-secondary text-uppercase mb-3">Details</h2>

<div class="row g-3 mb-4">
    <div class="col-md-8">
        <label for="name" class="form-label">Name <span class="text-danger" aria-hidden="true">*</span></label>
        <input id="name" type="text" name="name" value="{{ old('name', $product->name) }}"
               class="form-control @error('name') is-invalid @enderror" required maxlength="255" autofocus>
        @error('name')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-4">
        <label for="sku" class="form-label">SKU</label>
        <input id="sku" type="text" name="sku" value="{{ old('sku', $product->sku) }}"
               class="form-control text-uppercase @error('sku') is-invalid @enderror" maxlength="64"
               aria-describedby="skuHelp" autocomplete="off">
        @error('sku')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
        <div id="skuHelp" class="form-text">Optional. Letters, numbers and . _ / -</div>
    </div>

    <div class="col-md-4">
        <label for="unit" class="form-label">Unit</label>
        <input id="unit" type="text" name="unit" value="{{ old('unit', $product->unit) }}" list="unitOptions"
               class="form-control @error('unit') is-invalid @enderror" maxlength="30" autocomplete="off">
        <datalist id="unitOptions">
            @foreach (['piece', 'unit', 'hour', 'day', 'week', 'month', 'session', 'project', 'set', 'box', 'kg', 'm'] as $unit)
                <option value="{{ $unit }}"></option>
            @endforeach
        </datalist>
        @error('unit')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-12">
        <label for="description" class="form-label">Description</label>
        <textarea id="description" name="description" rows="3" maxlength="2000"
                  class="form-control @error('description') is-invalid @enderror">{{ old('description', $product->description) }}</textarea>
        @error('description')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
</div>

<h2 class="h6 text-body-secondary text-uppercase mb-3">Pricing</h2>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <label for="selling_price" class="form-label">Selling price <span class="text-danger" aria-hidden="true">*</span></label>
        <div class="input-group has-validation">
            <span class="input-group-text">{{ $currency }}</span>
            <input id="selling_price" type="number" name="selling_price" value="{{ old('selling_price', $product->selling_price) }}"
                   class="form-control @error('selling_price') is-invalid @enderror"
                   inputmode="decimal" step="0.01" min="0" max="9999999999.99" required placeholder="0.00">
            @error('selling_price')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
    </div>

    <div class="col-md-6">
        <label for="cost_price" class="form-label">Cost price <span class="badge text-bg-light border">Internal</span></label>
        <div class="input-group has-validation">
            <span class="input-group-text">{{ $currency }}</span>
            <input id="cost_price" type="number" name="cost_price" value="{{ old('cost_price', $product->cost_price) }}"
                   class="form-control @error('cost_price') is-invalid @enderror"
                   inputmode="decimal" step="0.01" min="0" max="9999999999.99" aria-describedby="costHelp">
            @error('cost_price')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
        <div id="costHelp" class="form-text">Optional. For your records only; never shown on invoices.</div>
    </div>
</div>

<h2 class="h6 text-body-secondary text-uppercase mb-3">Status</h2>

<div class="mb-4">
    <input type="hidden" name="is_active" value="0">
    <div class="form-check form-switch">
        <input id="is_active" type="checkbox" name="is_active" value="1" role="switch"
               class="form-check-input @error('is_active') is-invalid @enderror"
               @checked(old('is_active', $product->is_active))>
        <label for="is_active" class="form-check-label">Active (available for new invoices)</label>
        @error('is_active')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
</div>
