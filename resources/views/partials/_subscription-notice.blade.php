@isset($subscriptionNotice)
    <div class="alert alert-{{ $subscriptionNotice->level }} d-flex flex-wrap align-items-center justify-content-between gap-2" role="status" id="subscription-notice">
        <div>{{ $subscriptionNotice->message }}</div>
        @if (! request()->routeIs('billing.*'))
            <a href="{{ route('billing.show') }}" class="alert-link text-nowrap">View billing</a>
        @endif
    </div>
@endisset
