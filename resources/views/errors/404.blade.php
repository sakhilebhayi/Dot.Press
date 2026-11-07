@extends('errors._ecosystem-layout', ['code' => 404])

@section('rail')
    <svg class="er-rail" viewBox="0 0 640 64" fill="none" role="img" aria-label="A line with empty places for beads and no beads on it">
        <line class="er-line" x1="0" y1="32" x2="640" y2="32"/>
        <circle class="er-slot" cx="40" cy="32" r="8"/><circle class="er-slot" cx="148" cy="32" r="8"/><circle class="er-slot" cx="256" cy="32" r="8"/>
        <circle class="er-slot" cx="364" cy="32" r="8"/><circle class="er-slot" cx="472" cy="32" r="8"/><circle class="er-slot" cx="580" cy="32" r="8"/>
    </svg>
@endsection

@section('title', "Nothing on this line yet.")
@section('message', "The page you asked for is not here. It may have moved, or the link may have a typo.")

@section('actions')
    <a href="/" class="er-btn"><i aria-hidden="true"></i>Go to the start</a>
    @auth
    <a href="{{ route('dashboard') }}" class="er-link">Open your library</a>
    @else
        @if (Route::has('login'))
    <a href="{{ route('login') }}" class="er-link">Sign in</a>
        @endif
    @endauth
@endsection
