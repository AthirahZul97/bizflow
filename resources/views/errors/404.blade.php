@extends('layouts.app')

@section('title', 'Page not found')

@section('content')
    {{-- The exception message is deliberately not shown: it can name a model and record ID. --}}
    @include('errors._card', [
        'code' => 404,
        'heading' => 'Page not found',
        'message' => 'The page you were looking for doesn’t exist, or you don’t have access to it.',
    ])
@endsection
