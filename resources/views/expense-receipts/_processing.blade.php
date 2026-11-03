<div class="card shadow-sm">
    <div class="card-body p-4 text-center">
        @php $waiting = $receipt->queued_at->gt(now()->subMinutes(10)); @endphp

        @if ($waiting)
            <div class="spinner-border text-primary mb-3" role="status"><span class="visually-hidden">Reading receipt</span></div>
            <h2 class="h5">Reading your receipt&hellip;</h2>
            <p class="text-body-secondary mb-0">This page refreshes by itself. You can leave and come back from the Receipts list.</p>
            <script>setTimeout(function () { window.location.reload(); }, 3000);</script>
        @else
            <h2 class="h5">This is taking longer than expected</h2>
            <p class="text-body-secondary">The receipt is still waiting to be read. You can refresh this page, or discard the receipt and enter the expense yourself.</p>
            <a href="{{ route('expense-receipts.show', $receipt) }}" class="btn btn-outline-primary">Refresh</a>
        @endif
    </div>
</div>
