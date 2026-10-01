{{--
    When the next invoice is, and whether anything needs attention: occurrences that are
    due (waiting for the scheduler or "Generate now") and a failed generation.
    $recurring, $today; $compact for list rows.
--}}
@php
    $dueCount = $recurring->dueCount($today);
@endphp

@if ($recurring->isCancelled() || $recurring->isFinished())
    <span class="text-body-secondary">None</span>
@else
    <span class="text-nowrap">{{ $recurring->next_occurrence_on->format('d M Y') }}</span>
    @if ($recurring->isPaused())
        <span class="small text-body-secondary">(paused)</span>
    @endif
@endif

@if ($dueCount > 0)
    <span class="badge text-bg-warning" data-due-count>{{ $dueCount }} due</span>
@endif

@if ($recurring->last_generation_error && ! $recurring->isCancelled())
    <span class="badge text-bg-danger" data-generation-error>Generation failed</span>
@endif
