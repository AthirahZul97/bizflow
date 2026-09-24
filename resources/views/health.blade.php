@extends('layouts.app')

@section('title', 'System status')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h1 class="h3 mb-0">System status</h1>
                @if ($healthy)
                    <span class="badge text-bg-success fs-6">Healthy</span>
                @else
                    <span class="badge text-bg-danger fs-6">Degraded</span>
                @endif
            </div>

            <div class="card shadow-sm">
                <ul class="list-group list-group-flush">
                    <li class="list-group-item d-flex justify-content-between">
                        <span>Application</span>
                        <span class="text-success fw-semibold">Running</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span>Environment</span>
                        <span>{{ app()->environment() }}</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span>Laravel</span>
                        <span>{{ app()->version() }}</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span>PHP</span>
                        <span>{{ PHP_VERSION }}</span>
                    </li>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between">
                            <span>Database ({{ $database['driver'] }}: {{ $database['name'] }})</span>
                            <span @class(['fw-semibold', 'text-success' => $database['ok'], 'text-danger' => ! $database['ok']])>
                                {{ $database['ok'] ? 'Connected' : 'Unavailable' }}
                            </span>
                        </div>
                        @unless ($database['ok'])
                            <div class="small text-body-secondary mt-2 text-break">{{ $database['message'] }}</div>
                        @endunless
                    </li>
                </ul>
            </div>
        </div>
    </div>
@endsection
