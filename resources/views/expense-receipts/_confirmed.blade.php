<div class="row g-3">
    <div class="col-lg-5">
        @include('expense-receipts._preview')
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                @if (! empty($expense))
                    <h2 class="h5">Expense created</h2>
                    <p class="mb-1">{{ $expense->description }} &middot; {{ $expense->money() }}</p>
                    <p class="text-body-secondary">Confirmed {{ $receipt->confirmed_at?->format('d M Y, H:i') }}.</p>
                    <a href="{{ route('expenses.show', $expense) }}" class="btn btn-primary">View expense</a>
                @else
                    <h2 class="h5">Expense deleted</h2>
                    <p class="text-body-secondary">The expense created from this receipt has since been deleted.</p>
                    <a href="{{ route('expense-receipts.delete', $receipt) }}" class="btn btn-outline-danger">Discard receipt</a>
                @endif
            </div>
        </div>
    </div>
</div>
