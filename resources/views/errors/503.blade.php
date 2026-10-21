@extends('errors.layout')

@section('code', '503')
@section('title', 'COLISAP is being updated')
@section('message', 'The system is down for a short update and will be back in a few minutes. Please try again shortly.')

@section('actions')
    <a class="btn btn-primary" href="{{ url()->current() }}">Try again</a>
@endsection
