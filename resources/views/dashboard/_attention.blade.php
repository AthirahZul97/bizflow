@if ($outstanding['overdueCount'] > 0 || $dueSoon['count'] > 0 || $draftCount > 0)
    <div class="card border-warning shadow-sm mb-4" data-dashboard-attention>
        <div class="card-body">
            <h2 class="h5 mb-3">Needs attention</h2>

            @if ($outstanding['overdueCount'] > 0)
                <div class="mb-3" data-attention-overdue>
                    <div class="fw-semibold text-danger mb-2">
                        {{ $outstanding['overdueCount'] }} overdue {{ Str::plural('invoice', $outstanding['overdueCount']) }}
                        &middot; {{ \App\Support\Money::format($outstanding['overdueAmount']) }}
                    </div>
                    <ul class="list-group list-group-flush">
                        @foreach ($overdueInvoices as $invoice)
                            @php($days = (int) $invoice->due_date->diffInDays(today()))
                            <li class="list-group-item px-0 d-flex flex-wrap justify-content-between gap-2">
                                <span>
                                    <a href="{{ route('invoices.show', $invoice) }}">{{ $invoice->invoice_number }}</a>
                                    &middot; {{ $invoice->customer_name }}
                                    <span class="small text-danger">{{ $days }} {{ Str::plural('day', $days) }} overdue</span>
                                </span>
                                <span class="text-nowrap">{{ $invoice->money('total') }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <a href="{{ route('invoices.index', ['status' => 'overdue']) }}" class="small">View all overdue ({{ $outstanding['overdueCount'] }})</a>
                </div>
            @endif

            @if ($dueSoon['count'] > 0)
                <div class="mb-2" data-attention-due-soon>
                    <a href="{{ route('invoices.index', ['status' => 'issued']) }}">{{ $dueSoon['count'] }} {{ Str::plural('invoice', $dueSoon['count']) }} due in the next 7 days</a>
                    &middot; {{ \App\Support\Money::format($dueSoon['amount']) }}
                </div>
            @endif

            @if ($draftCount > 0)
                <div data-attention-drafts>
                    <a href="{{ route('invoices.index', ['status' => 'draft']) }}">{{ $draftCount }} draft {{ Str::plural('invoice', $draftCount) }} not yet issued</a>
                    <span class="small text-body-secondary">Drafts are not counted in any total.</span>
                </div>
            @endif
        </div>
    </div>
@endif
