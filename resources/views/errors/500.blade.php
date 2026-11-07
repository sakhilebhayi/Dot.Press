@extends('errors._ecosystem-layout', ['code' => 500])

@section('rail')
    <svg class="er-rail" viewBox="0 0 640 64" fill="none" role="img" aria-label="A line of beads with a break in the rail and a square mark where it breaks">
        <line class="er-line" x1="0" y1="32" x2="270" y2="32"/>
        <line class="er-line" x1="370" y1="32" x2="640" y2="32"/>
        <circle class="er-bead" cx="40" cy="32" r="8"/><circle class="er-bead" cx="148" cy="32" r="8"/>
        <rect class="er-bad" x="298" y="18" width="28" height="28" rx="3" transform="rotate(45 312 32)"/>
        <circle class="er-hollow" cx="472" cy="32" r="8"/><circle class="er-hollow" cx="580" cy="32" r="8"/>
    </svg>
@endsection

@section('title', 'Something broke on our side.')
@section('message', 'That is our fault, not yours. Try again in a moment. If it keeps happening, tell us through InfoDot and mention the time it happened.')

@section('actions')
    <a href="{{ url()->current() }}" class="er-btn"><i aria-hidden="true"></i>Try again</a>
    <a href="/" class="er-link">Go to the start</a>
@endsection
