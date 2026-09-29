{{--
    Shared error card. Expects $code, $heading and $message (plain text; escaped here).
    Never pass exception details that could reveal records, SQL or file paths.
--}}
<div class="row justify-content-center py-4">
    <div class="col-sm-10 col-md-8 col-lg-6">
        <div class="card shadow-sm text-center" data-error-page="{{ $code }}">
            <div class="card-body p-4 p-md-5">
                <div class="display-5 fw-bold text-body-secondary mb-2">{{ $code }}</div>
                <h1 class="h4 mb-3">{{ $heading }}</h1>
                <p class="text-body-secondary mb-4">{{ $message }}</p>
                @auth
                    <a href="{{ route('dashboard') }}" class="btn btn-primary">Go to dashboard</a>
                @else
                    <a href="{{ url('/') }}" class="btn btn-primary">Go to the home page</a>
                @endauth
            </div>
        </div>
    </div>
</div>
