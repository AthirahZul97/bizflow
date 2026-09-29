@extends('layouts.app')

@section('title', 'Page expired')

@section('content')
    @include('errors._card', [
        'code' => 419,
        'heading' => 'This page has expired',
        'message' => 'Your session timed out before the form was sent. Go back, refresh the page and try again.',
    ])
@endsection
