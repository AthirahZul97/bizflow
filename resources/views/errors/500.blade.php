{{--
    Standalone on purpose: a 500 can be caused by the database or session, and the main
    layout reads both (the signed-in user and the CSRF token). This page uses neither and
    never shows exception details.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Something went wrong · {{ config('app.name', 'BizFlow') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="d-flex flex-column min-vh-100 bg-body-tertiary">
    <nav class="navbar bg-dark" data-bs-theme="dark">
        <div class="container">
            <a class="navbar-brand fw-semibold" href="{{ url('/') }}">{{ config('app.name', 'BizFlow') }}</a>
        </div>
    </nav>

    <main class="flex-grow-1 py-4">
        <div class="container">
            <div class="row justify-content-center py-4">
                <div class="col-sm-10 col-md-8 col-lg-6">
                    <div class="card shadow-sm text-center" data-error-page="500">
                        <div class="card-body p-4 p-md-5">
                            <div class="display-5 fw-bold text-body-secondary mb-2">500</div>
                            <h1 class="h4 mb-3">Something went wrong</h1>
                            <p class="text-body-secondary mb-4">An unexpected error occurred on our side. Please try again in a moment.</p>
                            <a href="{{ url('/') }}" class="btn btn-primary">Go to the home page</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <footer class="py-3 border-top bg-body">
        <div class="container small text-body-secondary">&copy; {{ date('Y') }} {{ config('app.name', 'BizFlow') }}</div>
    </footer>
</body>
</html>
