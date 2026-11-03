@php $unreadable = $receipt->status === \App\Enums\ExpenseReceiptStatus::Unreadable; @endphp

<div class="row g-3">
    <div class="col-lg-5">
        @include('expense-receipts._preview')
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h2 class="h5">{{ $unreadable ? 'We couldn’t find the total on this receipt' : 'We couldn’t read this receipt' }}</h2>

                @if ($unreadable)
                    @foreach ($receipt->warnings() as $warning)
                        <p class="text-body-secondary mb-1">{{ $warning['message'] }}</p>
                    @endforeach
                @elseif ($receipt->last_error)
                    <p class="text-body-secondary">{{ $receipt->last_error }}</p>
                @endif

                <p class="mb-3">You can try again, or fill in the expense yourself while looking at the receipt.</p>

                <div class="d-grid d-sm-flex gap-2">
                    <form method="POST" action="{{ route('expense-receipts.retry', $receipt) }}">
                        @csrf
                        <button type="submit" class="btn btn-primary w-100">Retry reading</button>
                    </form>
                    <form method="POST" action="{{ route('expense-receipts.manual', $receipt) }}">
                        @csrf
                        <button type="submit" class="btn btn-outline-primary w-100">Enter details myself</button>
                    </form>
                    <a href="{{ route('expense-receipts.delete', $receipt) }}" class="btn btn-outline-danger">Discard</a>
                </div>
            </div>
        </div>
    </div>
</div>
