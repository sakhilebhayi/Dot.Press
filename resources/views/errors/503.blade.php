@extends('errors._ecosystem-layout', ['code' => 503])

@section('rail')
    <svg class="er-rail" viewBox="0 0 640 64" fill="none" role="img" aria-label="A line of beads paused on one amber bead with the rest still hollow">
        <line class="er-line" x1="0" y1="32" x2="640" y2="32"/>
        <circle class="er-bead" cx="40" cy="32" r="8"/><circle class="er-bead" cx="148" cy="32" r="8"/>
        <circle class="er-here" cx="256" cy="32" r="10"/>
        <rect x="251" y="6" width="3" height="12" fill="var(--ink)"/><rect x="258" y="6" width="3" height="12" fill="var(--ink)"/>
        <circle class="er-hollow" cx="364" cy="32" r="8"/><circle class="er-hollow" cx="472" cy="32" r="8"/><circle class="er-hollow" cx="580" cy="32" r="8"/>
    </svg>
@endsection

@section('title', 'Back in a moment.')
@section('message', 'Dot.Press is down for maintenance. Anything you had already saved is kept. Try again in a few minutes.')

@section('actions')
    <a href="{{ url()->current() }}" class="er-btn"><i aria-hidden="true"></i>Try again</a>
@endsection
