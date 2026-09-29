@extends('layouts.app')

@section('title', 'Not allowed')

@section('content')
    @include('errors._card', [
        'code' => 403,
        'heading' => 'This action isn’t allowed',
        // Policies give a plain reason here, e.g. "Only draft invoices can be edited."
        'message' => $exception->getMessage() ?: 'You don’t have permission to do that.',
    ])
@endsection
