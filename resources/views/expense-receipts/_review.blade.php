@php
    $labels = [
        'merchant' => 'Merchant', 'receipt_number' => 'Receipt no.', 'date' => 'Date', 'subtotal' => 'Subtotal',
        'tax' => 'SST / tax', 'total' => 'Total', 'currency' => 'Currency', 'payment_method' => 'Paid by', 'category' => 'Category',
    ];
    $confidenceBadge = function (?float $confidence): array {
        return match (true) {
            $confidence === null => ['text-bg-secondary', 'Unverified'],
            $confidence >= 0.85 => ['text-bg-success', 'High'],
            $confidence >= \App\Ocr\ReceiptExtractionNormalizer::LOW_CONFIDENCE => ['text-bg-info', 'Medium'],
            default => ['text-bg-warning', 'Low'],
        };
    };
@endphp

<div class="row g-3">
    <div class="col-lg-5">
        <details class="d-lg-none-open" open>
            <summary class="mb-2 d-lg-none text-primary">Show / hide original receipt</summary>
            @include('expense-receipts._preview')
        </details>

        @if (! $receipt->isManual())
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-body fw-semibold">What was read</div>
                <ul class="list-group list-group-flush small">
                    @foreach ($labels as $name => $label)
                        <li class="list-group-item d-flex justify-content-between gap-2">
                            <span class="text-body-secondary">{{ $label }}</span>
                            @if ($receipt->field($name) !== null)
                                @php [$class, $text] = $confidenceBadge($receipt->confidence($name)); @endphp
                                <span class="text-end text-break">{{ $receipt->field($name) }} <span class="badge {{ $class }}">{{ $text }}</span></span>
                            @else
                                <span class="badge text-bg-warning">Not found</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>

    <div class="col-lg-7">
        @if ($receipt->isManual())
            <div class="alert alert-info">Fill in the details from the receipt. Nothing was read automatically.</div>
        @endif

        @foreach ($receipt->warnings() as $warning)
            <div class="alert alert-warning py-2" role="alert" data-testid="ocr-warning">{{ $warning['message'] }}</div>
        @endforeach

        @if ($similar)
            <div class="alert alert-warning py-2" role="alert" data-testid="similar-expense-warning">
                You already have an expense for this payee, date and amount
                (<a href="{{ route('expenses.show', $similar) }}">view it</a>). Continue only if this is a different purchase.
            </div>
        @endif

        <div class="card shadow-sm">
            <div class="card-body p-3 p-md-4">
                <h2 class="h5 mb-1">Check and confirm</h2>
                <p class="text-body-secondary small">Every field can be changed. No expense exists until you confirm.</p>

                <form method="POST" action="{{ route('expense-receipts.confirm', $receipt) }}" novalidate
                      onsubmit="this.querySelector('[type=submit]').disabled = true">
                    @include('expenses._form')

                    <div class="sticky-bottom bg-body border-top py-2 d-grid d-sm-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-lg">Confirm &amp; create expense</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="d-grid d-sm-flex gap-2 mt-3">
            @if (! $receipt->isManual() || $receipt->extracted_at !== null)
                <form method="POST" action="{{ route('expense-receipts.retry', $receipt) }}"
                      onsubmit="return confirm('Read the receipt again? Your edits on this page will be lost.')">
                    @csrf
                    <button type="submit" class="btn btn-outline-secondary w-100">Retry reading</button>
                </form>
            @endif
            <a href="{{ route('expense-receipts.delete', $receipt) }}" class="btn btn-outline-danger">Discard receipt</a>
        </div>
    </div>
</div>
