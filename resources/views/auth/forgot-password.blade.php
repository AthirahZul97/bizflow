@extends('layouts.app')

@section('title', 'Forgot your password')

@section('content')
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-7 col-lg-5">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h4 mb-3">Forgot your password?</h1>
                    <p class="text-body-secondary">Enter your email address and we’ll send you a link to choose a new one.</p>

                    <form method="POST" action="{{ route('password.email') }}" novalidate>
                        @csrf

                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input id="email" type="email" name="email" value="{{ old('email') }}"
                                   class="form-control @error('email') is-invalid @enderror"
                                   required autofocus autocomplete="email">
                            @error('email')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <button type="submit" class="btn btn-primary w-100">Email me a reset link</button>
                    </form>
                </div>
            </div>

            <p class="text-center text-body-secondary small mt-3">
                <a href="{{ route('login') }}">Back to log in</a>
            </p>
        </div>
    </div>
@endsection
