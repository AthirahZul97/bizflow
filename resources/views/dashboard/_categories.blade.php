<div class="card shadow-sm h-100" data-dashboard-categories>
    <div class="card-body">
        <h2 class="h5 mb-3">Expenses by category <span class="small text-body-secondary fw-normal">&middot; {{ $period->label() }}</span></h2>

        @forelse ($categories as $row)
            <div class="mb-3" data-category-row="{{ $row['category']->value }}">
                <div class="d-flex justify-content-between gap-2 small">
                    <a href="{{ route('expenses.index', ['category' => $row['category']->value, 'from' => $period->startDate(), 'to' => $period->endDate()]) }}">{{ $row['category']->label() }}</a>
                    <span class="text-nowrap">{{ \App\Support\Money::format($row['amount']) }} &middot; {{ $row['percent'] }}%</span>
                </div>
                <div class="progress" role="progressbar" aria-label="{{ $row['category']->label() }} share of expenses"
                     aria-valuenow="{{ $row['percent'] }}" aria-valuemin="0" aria-valuemax="100" style="height: 0.5rem">
                    <div class="progress-bar" style="width: {{ $row['percent'] }}%"></div>
                </div>
            </div>
        @empty
            <p class="text-body-secondary mb-0">No expenses recorded in this period.</p>
        @endforelse
    </div>
</div>
