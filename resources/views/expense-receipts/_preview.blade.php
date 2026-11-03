{{-- The stored file is private: it is only ever shown through the policy-checked file route. --}}
<div class="card shadow-sm mb-3">
    <div class="card-header bg-body d-flex align-items-center justify-content-between">
        <span class="fw-semibold">Original receipt</span>
        @if ($receipt->hasFile())
            <a href="{{ route('expense-receipts.file', ['expense_receipt' => $receipt, 'download' => 1]) }}" class="small">Download</a>
        @endif
    </div>
    <div class="card-body text-center">
        @if (! $receipt->hasFile())
            <span class="text-body-secondary">The file is no longer available.</span>
        @elseif ($receipt->isImage())
            <a href="{{ route('expense-receipts.file', $receipt) }}" target="_blank" rel="noopener">
                <img src="{{ route('expense-receipts.file', $receipt) }}" alt="Uploaded receipt" class="img-fluid rounded border"
                     style="max-height: 70vh" loading="lazy">
            </a>
        @else
            <p class="mb-2">PDF receipt</p>
            <a href="{{ route('expense-receipts.file', ['expense_receipt' => $receipt, 'download' => 1]) }}" class="btn btn-outline-primary">Download PDF</a>
        @endif
    </div>
</div>
