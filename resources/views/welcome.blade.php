@extends('layouts.app')

@section('content')
    <div class="py-5 text-center">
        <h1 class="display-5 fw-bold">{{ config('app.name', 'BizFlow') }}</h1>
        <p class="lead text-body-secondary mb-4">Simple business management for small businesses.</p>
        <a href="{{ route('health') }}" class="btn btn-outline-primary">Check system status</a>
    </div>
@endsection
