{{--
    One invoice line. $index is the row key ("__INDEX__" in the JavaScript template),
    $item the line's values and $products the products the line may use.
    The line total is display-only; the server always calculates it.
--}}
@php
    $field = fn (string $name) => "items[{$index}][{$name}]";
    $errorKey = fn (string $name) => "items.{$index}.{$name}";
    $value = fn (string $name) => $item[$name] ?? '';
@endphp

<div class="invoice-line border-bottom py-3" data-invoice-line>
    <div class="row g-2 align-items-start">
        <div class="col-md-3">
            <label class="form-label small d-md-none" for="item_{{ $index }}_product_id">Product</label>
            <select id="item_{{ $index }}_product_id" name="{{ $field('product_id') }}" data-line-product
                    class="form-select form-select-sm @error($errorKey('product_id')) is-invalid @enderror" aria-label="Product">
                <option value="">Manual line</option>
                @foreach ($products as $product)
                    <option value="{{ $product->id }}"
                            data-name="{{ $product->name }}"
                            data-description="{{ $product->description }}"
                            data-unit="{{ $product->unit }}"
                            data-price="{{ $product->selling_price }}"
                            @selected((string) $value('product_id') === (string) $product->id)>
                        {{ $product->name }}@if ($product->sku) ({{ $product->sku }})@endif @if (! $product->is_active) [inactive]@endif
                    </option>
                @endforeach
            </select>
            @error($errorKey('product_id'))
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="col-md-3">
            <label class="form-label small d-md-none" for="item_{{ $index }}_name">Item</label>
            <input id="item_{{ $index }}_name" type="text" name="{{ $field('name') }}" value="{{ $value('name') }}" data-line-name
                   class="form-control form-control-sm @error($errorKey('name')) is-invalid @enderror" maxlength="255" aria-label="Item name">
            @error($errorKey('name'))
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="col-4 col-md-1">
            <label class="form-label small d-md-none" for="item_{{ $index }}_quantity">Qty</label>
            <input id="item_{{ $index }}_quantity" type="text" inputmode="decimal" name="{{ $field('quantity') }}" value="{{ $value('quantity') }}" data-line-quantity
                   class="form-control form-control-sm text-end @error($errorKey('quantity')) is-invalid @enderror" aria-label="Quantity">
            @error($errorKey('quantity'))
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="col-4 col-md-1">
            <label class="form-label small d-md-none" for="item_{{ $index }}_unit">Unit</label>
            <input id="item_{{ $index }}_unit" type="text" name="{{ $field('unit') }}" value="{{ $value('unit') }}" data-line-unit
                   class="form-control form-control-sm @error($errorKey('unit')) is-invalid @enderror" maxlength="30" aria-label="Unit">
            @error($errorKey('unit'))
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="col-4 col-md-2">
            <label class="form-label small d-md-none" for="item_{{ $index }}_unit_price">Unit price</label>
            <input id="item_{{ $index }}_unit_price" type="text" inputmode="decimal" name="{{ $field('unit_price') }}" value="{{ $value('unit_price') }}" data-line-price
                   class="form-control form-control-sm text-end @error($errorKey('unit_price')) is-invalid @enderror" aria-label="Unit price">
            @error($errorKey('unit_price'))
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="col-8 col-md-1 text-md-end small pt-md-1">
            <span class="d-md-none text-body-secondary">Line total: </span>
            <span data-line-total>{{ $item['line_total'] ?? '' }}</span>
        </div>

        <div class="col-4 col-md-1 text-end">
            <button type="button" class="btn btn-sm btn-outline-danger" data-remove-line aria-label="Remove line">&times;</button>
        </div>

        <div class="col-md-9 offset-md-3">
            <label class="visually-hidden" for="item_{{ $index }}_description">Description</label>
            <textarea id="item_{{ $index }}_description" name="{{ $field('description') }}" rows="1" maxlength="2000" data-line-description
                      class="form-control form-control-sm @error($errorKey('description')) is-invalid @enderror"
                      placeholder="Description (optional)">{{ $value('description') }}</textarea>
            @error($errorKey('description'))
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
    </div>
</div>
