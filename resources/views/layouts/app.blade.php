<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@hasSection('title')@yield('title') · @endif{{ config('app.name', 'BizFlow') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="d-flex flex-column min-vh-100 bg-body-tertiary">
    <nav class="navbar navbar-expand-md bg-dark" data-bs-theme="dark">
        <div class="container">
            <a class="navbar-brand fw-semibold" href="{{ url('/') }}">{{ config('app.name', 'BizFlow') }}</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav"
                    aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="mainNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link @if (request()->routeIs('health')) active @endif" href="{{ route('health') }}">System status</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <main class="flex-grow-1 py-4">
        <div class="container">
            @yield('content')
        </div>
    </main>

    <footer class="py-3 border-top bg-body">
        <div class="container small text-body-secondary">
            &copy; {{ date('Y') }} {{ config('app.name', 'BizFlow') }}
        </div>
    </footer>
</body>
</html>
