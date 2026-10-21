@extends('errors.layout')

@section('code', '419')
@section('title', 'This page expired')
@section('message', 'For your security, the page timed out after a while without activity. Reload it and sign in again if asked — then repeat what you were doing.')

@section('actions')
    <a class="btn btn-primary" href="{{ url()->current() }}">Reload the page</a>
    <a class="btn btn-secondary" href="{{ url('/admin') }}">Go to the dashboard</a>
@endsection
