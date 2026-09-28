@extends('layouts.app')

@section('content')
    <div class="py-5 text-center">
        <h1 class="display-5 fw-bold">{{ config('app.name', 'BizFlow') }}</h1>
        <p class="lead text-body-secondary mb-4">Simple business management for small businesses.</p>
        <div class="d-flex flex-wrap justify-content-center gap-2">
            @guest
                <a href="{{ route('register') }}" class="btn btn-primary">Create an account</a>
                <a href="{{ route('login') }}" class="btn btn-outline-secondary">Log in</a>
            @endguest
            @auth
                <a href="{{ route('dashboard') }}" class="btn btn-primary">Go to dashboard</a>
            @endauth
            <a href="{{ route('health') }}" class="btn btn-outline-primary">Check system status</a>
        </div>
    </div>
@endsection
