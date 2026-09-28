@csrf

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <label for="expense_date" class="form-label">Date <span class="text-danger" aria-hidden="true">*</span></label>
        <input id="expense_date" type="date" name="expense_date"
               value="{{ old('expense_date', $expense->expense_date?->toDateString()) }}"
               min="2000-01-01" max="{{ today()->toDateString() }}"
               class="form-control @error('expense_date') is-invalid @enderror" required>
        @error('expense_date')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-8">
        <label for="category" class="form-label">Category <span class="text-danger" aria-hidden="true">*</span></label>
        <select id="category" name="category" class="form-select @error('category') is-invalid @enderror" required>
            <option value="">Choose a category</option>
            @foreach (\App\Enums\ExpenseCategory::cases() as $case)
                <option value="{{ $case->value }}" @selected(old('category', $expense->category?->value) === $case->value)>{{ $case->label() }}</option>
            @endforeach
        </select>
        @error('category')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-8">
        <label for="description" class="form-label">Description <span class="text-danger" aria-hidden="true">*</span></label>
        <input id="description" type="text" name="description" value="{{ old('description', $expense->description) }}"
               class="form-control @error('description') is-invalid @enderror" required maxlength="255"
               placeholder="e.g. October office rent">
        @error('description')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-4">
        <label for="amount" class="form-label">Amount <span class="text-danger" aria-hidden="true">*</span></label>
        <div class="input-group has-validation">
            <span class="input-group-text">{{ config('bizflow.currency.symbol') }}</span>
            <input id="amount" type="text" inputmode="decimal" name="amount" value="{{ old('amount', $expense->amount) }}"
                   class="form-control text-end @error('amount') is-invalid @enderror" required placeholder="0.00">
            @error('amount')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
    </div>

    <div class="col-md-8">
        <label for="payee" class="form-label">Payee</label>
        <input id="payee" type="text" name="payee" value="{{ old('payee', $expense->payee) }}"
               class="form-control @error('payee') is-invalid @enderror" maxlength="255" aria-describedby="payeeHelp">
        @error('payee')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
        <div id="payeeHelp" class="form-text">Optional. Who you paid, e.g. the landlord or supplier.</div>
    </div>

    <div class="col-12">
        <label for="notes" class="form-label">Notes</label>
        <textarea id="notes" name="notes" rows="3" maxlength="5000" aria-describedby="notesHelp"
                  class="form-control @error('notes') is-invalid @enderror">{{ old('notes', $expense->notes) }}</textarea>
        @error('notes')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
        <div id="notesHelp" class="form-text">Optional, e.g. receipt number or how it was paid.</div>
    </div>
</div>
