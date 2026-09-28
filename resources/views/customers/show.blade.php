@extends('layouts.app')

@section('title', $customer->name)

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-9">
            <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
                <div>
                    <h1 class="h3 mb-0">{{ $customer->name }}</h1>
                    @if ($customer->company_name)
                        <p class="text-body-secondary mb-0">{{ $customer->company_name }}</p>
                    @endif
                </div>
                <div class="d-flex gap-2">
                    <a href="{{ route('customers.edit', $customer) }}" class="btn btn-outline-primary">Edit</a>
                    <a href="{{ route('customers.delete', $customer) }}" class="btn btn-outline-danger">Delete</a>
                </div>
            </div>

            <div class="card shadow-sm mb-3">
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-3">Email</dt>
                        <dd class="col-sm-9 text-break">
                            @if ($customer->email)
                                <a href="mailto:{{ $customer->email }}">{{ $customer->email }}</a>
                            @else
                                <span class="text-body-secondary">&mdash;</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3">Phone</dt>
                        <dd class="col-sm-9">{{ $customer->phone ?? '—' }}</dd>

                        <dt class="col-sm-3">Address</dt>
                        <dd class="col-sm-9">
                            @forelse ($customer->addressLines() as $line)
                                {{ $line }}@if (! $loop->last)<br>@endif
                            @empty
                                <span class="text-body-secondary">&mdash;</span>
                            @endforelse
                        </dd>

                        <dt class="col-sm-3">Notes</dt>
                        <dd class="col-sm-9 mb-0">
                            @if ($customer->notes)
                                {!! nl2br(e($customer->notes)) !!}
                            @else
                                <span class="text-body-secondary">&mdash;</span>
                            @endif
                        </dd>
                    </dl>
                </div>
            </div>

            <a href="{{ route('customers.index') }}">&larr; Back to customers</a>
        </div>
    </div>
@endsection
