{{--
    The line editor, notes and totals card, shared by the invoice form and the recurring
    invoice form (resources/js/invoice-form.js drives both).

    $record     the invoice or recurring invoice (notes, discount_amount, tax_label, tax_rate)
    $lines      the lines to show; $blankRows spare empty rows; $products the selectable products
    $totals     ['subtotal', 'tax_amount', 'total'] formatted for display, or null
    $notesHelp  the hint under the notes field
--}}
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
                  class="form-control @error('notes') is-invalid @enderror">{{ old('notes', $record->notes) }}</textarea>
        @error('notes')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
        <div id="notesHelp" class="form-text">{{ $notesHelp }}</div>
    </div>

    <div class="col-lg-5">
        <div class="card bg-body-tertiary">
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <span>Subtotal</span>
                    <span data-subtotal>{{ $totals['subtotal'] ?? '' }}</span>
                </div>

                <div class="row g-2 align-items-center mb-2">
                    <label for="discount_amount" class="col-5 col-form-label">Discount</label>
                    <div class="col-7">
                        <div class="input-group input-group-sm has-validation">
                            <span class="input-group-text">{{ $currency }}</span>
                            <input id="discount_amount" type="text" inputmode="decimal" name="discount_amount" data-discount
                                   value="{{ old('discount_amount', $record->discount_amount ?? '0.00') }}"
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
                               value="{{ old('tax_label', $record->tax_label) }}"
                               class="form-control form-control-sm @error('tax_label') is-invalid @enderror">
                        @error('tax_label')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-7">
                        <div class="input-group input-group-sm has-validation">
                            <label for="tax_rate" class="visually-hidden">Tax rate</label>
                            <input id="tax_rate" type="text" inputmode="decimal" name="tax_rate" data-tax-rate
                                   value="{{ old('tax_rate', $record->tax_rate ?? '0.00') }}"
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
                    <span data-tax-amount>{{ $totals['tax_amount'] ?? '' }}</span>
                </div>

                <div class="d-flex justify-content-between border-top pt-2 fw-semibold fs-5">
                    <span>Total</span>
                    <span data-total>{{ $totals['total'] ?? '' }}</span>
                </div>
                <div class="form-text">Totals are calculated when you save.</div>
            </div>
        </div>
    </div>
</div>
