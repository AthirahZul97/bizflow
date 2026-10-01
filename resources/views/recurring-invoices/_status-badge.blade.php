{{-- The stored status, or "Finished" once an active or paused schedule has passed its last possible date. --}}
@if (! $recurring->isCancelled() && $recurring->isFinished())
    <span class="badge text-bg-secondary">Finished</span>
@else
    <span @class([
        'badge',
        'text-bg-success' => $recurring->isActive(),
        'text-bg-warning' => $recurring->isPaused(),
        'text-bg-dark' => $recurring->isCancelled(),
    ])>{{ $recurring->status->label() }}</span>
@endif
