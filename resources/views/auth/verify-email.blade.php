@extends('layouts.app')

@section('title', 'Verify your email')

@section('content')
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-7 col-lg-5">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h4 mb-3">Verify your email address</h1>
                    <p class="text-body-secondary">
                        We sent a verification link to <strong class="text-break">{{ auth()->user()->email }}</strong>.
                        Open it to confirm the address. Until then, sending invoices by email may be unavailable.
                    </p>

                    <form method="POST" action="{{ route('verification.send') }}" class="d-grid">
                        @csrf
                        <button type="submit" class="btn btn-primary">Send the link again</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
