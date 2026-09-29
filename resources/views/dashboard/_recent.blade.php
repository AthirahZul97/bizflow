<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card shadow-sm h-100" data-dashboard-recent-invoices>
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="h5 mb-0">Recent invoices</h2>
                    <a href="{{ route('invoices.index') }}" class="small">View all</a>
                </div>
                @forelse ($recentInvoices as $invoice)
                    <div class="d-flex flex-wrap justify-content-between gap-2 py-2 border-bottom" data-recent-invoice>
                        <span>
                            <a href="{{ route('invoices.show', $invoice) }}">{{ $invoice->invoice_number ?? 'Draft' }}</a>
                            &middot; {{ $invoice->customer_name }}
                        </span>
                        <span class="d-flex align-items-center gap-2 text-nowrap">
                            {{ $invoice->money('total') }}
                            @include('invoices._status-badge')
                        </span>
                    </div>
                @empty
                    <p class="text-body-secondary mb-0">No invoices yet.</p>
                @endforelse
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card shadow-sm h-100" data-dashboard-recent-expenses>
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="h5 mb-0">Recent expenses</h2>
                    <a href="{{ route('expenses.index') }}" class="small">View all</a>
                </div>
                @forelse ($recentExpenses as $expense)
                    <div class="d-flex flex-wrap justify-content-between gap-2 py-2 border-bottom" data-recent-expense>
                        <span>
                            <span class="small text-body-secondary">{{ $expense->expense_date->format('d M') }}</span>
                            <a href="{{ route('expenses.show', $expense) }}">{{ $expense->description }}</a>
                            <span class="badge text-bg-light border">{{ $expense->category->label() }}</span>
                        </span>
                        <span class="text-nowrap">{{ $expense->money() }}</span>
                    </div>
                @empty
                    <p class="text-body-secondary mb-0">No expenses yet.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
