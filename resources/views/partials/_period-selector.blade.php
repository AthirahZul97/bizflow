{{--
    Period selector shared by the Dashboard and Reports.
    Expects: $period (ReportingPeriod), $periodRoute (route name), $periodPresets (list of preset keys).
    Optional: $periodQuery (extra query parameters to keep, e.g. the invoice report view).
--}}
@php($periodQuery = $periodQuery ?? [])

<div class="d-flex flex-column align-items-md-end gap-2 d-print-none">
    <div class="btn-group btn-group-sm flex-wrap" role="group" aria-label="Period">
        @foreach ($periodPresets as $key)
            <a href="{{ route($periodRoute, ['period' => $key] + $periodQuery) }}"
               @class(['btn', 'btn-primary' => $period->key === $key, 'btn-outline-primary' => $period->key !== $key])
               @if ($period->key === $key) aria-current="true" @endif>{{ \App\Support\ReportingPeriod::LABELS[$key] }}</a>
        @endforeach
    </div>

    <form method="GET" action="{{ route($periodRoute) }}" class="d-flex flex-wrap align-items-center gap-1" aria-label="Custom period">
        <input type="hidden" name="period" value="custom">
        @foreach ($periodQuery as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach
        <label for="period_from" class="visually-hidden">From</label>
        <input id="period_from" type="date" name="from" value="{{ $period->startDate() }}"
               min="{{ \App\Support\ReportingPeriod::EARLIEST_DATE }}" max="{{ today()->toDateString() }}"
               class="form-control form-control-sm w-auto">
        <span class="small text-body-secondary">to</span>
        <label for="period_to" class="visually-hidden">To</label>
        <input id="period_to" type="date" name="to" value="{{ $period->endDate() }}"
               min="{{ \App\Support\ReportingPeriod::EARLIEST_DATE }}" max="{{ today()->toDateString() }}"
               class="form-control form-control-sm w-auto">
        <button type="submit" @class(['btn', 'btn-sm', 'btn-primary' => $period->key === 'custom', 'btn-outline-primary' => $period->key !== 'custom'])>Custom</button>
    </form>
</div>
