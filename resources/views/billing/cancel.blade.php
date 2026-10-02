@extends('layouts.app')

@section('title', 'Cancel subscription')

@section('content')
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-8 col-lg-6">
            <div class="card border-danger shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h4 mb-3">Cancel subscription</h1>

                    <p>Cancel your <strong>{{ $subscription->plan->name }}</strong> subscription?</p>
                    <p class="text-body-secondary">
                        You keep full access until <strong>{{ $subscription->current_period_ends_at->format('d M Y') }}</strong>.
                        After that your account becomes read-only: you can still view and download everything, but you
                        can't add or change anything until you choose a plan again. Nothing is deleted. You can undo
                        this at any time before that date.
                    </p>

                    <form method="POST" action="{{ route('billing.cancel') }}" class="d-flex flex-wrap gap-2">
                        @csrf
                        <button type="submit" class="btn btn-danger">Cancel subscription</button>
                        <a href="{{ route('billing.show') }}" class="btn btn-outline-secondary">Keep it</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
