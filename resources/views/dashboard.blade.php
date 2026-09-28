@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <h1 class="h3 mb-3">Dashboard</h1>

    <div class="card shadow-sm">
        <div class="card-body">
            <p class="mb-1">Welcome, <strong>{{ Auth::user()->name }}</strong>.</p>
            <p class="text-body-secondary mb-0">Your business modules will appear here.</p>
        </div>
    </div>
@endsection
