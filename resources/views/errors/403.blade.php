@extends('errors._ecosystem-layout', ['code' => 403])

@section('rail')
    <svg class="er-rail" viewBox="0 0 640 64" fill="none" role="img" aria-label="A line of solid beads with one hollow bead standing apart from the line">
        <line class="er-line" x1="0" y1="40" x2="640" y2="40"/>
        <circle class="er-bead" cx="40" cy="40" r="8"/><circle class="er-bead" cx="148" cy="40" r="8"/><circle class="er-bead" cx="256" cy="40" r="8"/>
        <circle class="er-hollow" cx="364" cy="10" r="8"/>
        <circle class="er-bead" cx="472" cy="40" r="8"/><circle class="er-bead" cx="580" cy="40" r="8"/>
    </svg>
@endsection

@section('title', "This is not open to you.")
@section('message', "It may belong to a team you are not in, need a role you do not have, or need you to sign in first. If that seems wrong, ask whoever shared it with you.")

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
