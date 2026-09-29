<div class="d-flex flex-column align-items-md-end gap-2">
    <div class="btn-group btn-group-sm" role="group" aria-label="Period">
        @foreach (\App\Support\DashboardPeriod::OPTIONS as $key => $label)
            <a href="{{ route('dashboard', ['period' => $key]) }}"
               @class(['btn', 'btn-primary' => $period->key === $key, 'btn-outline-primary' => $period->key !== $key])
               @if ($period->key === $key) aria-current="true" @endif>{{ $label }}</a>
        @endforeach
    </div>

    <form method="GET" action="{{ route('dashboard') }}" class="d-flex flex-wrap align-items-center gap-1" aria-label="Custom period">
        <input type="hidden" name="period" value="custom">
        <label for="period_from" class="visually-hidden">From</label>
        <input id="period_from" type="date" name="from" value="{{ $period->startDate() }}" max="{{ today()->toDateString() }}"
               class="form-control form-control-sm w-auto">
        <span class="small text-body-secondary">to</span>
        <label for="period_to" class="visually-hidden">To</label>
        <input id="period_to" type="date" name="to" value="{{ $period->endDate() }}" max="{{ today()->toDateString() }}"
               class="form-control form-control-sm w-auto">
        <button type="submit" @class(['btn', 'btn-sm', 'btn-primary' => $period->key === 'custom', 'btn-outline-primary' => $period->key !== 'custom'])>Custom</button>
    </form>
</div>
