@extends('errors._ecosystem-layout', ['code' => 419])

@section('rail')
    <svg class="er-rail" viewBox="0 0 640 64" fill="none" role="img" aria-label="A line of beads that turns into a dotted line and stops">
        <line class="er-line" x1="0" y1="32" x2="300" y2="32"/>
        <line class="er-dash" x1="300" y1="32" x2="640" y2="32"/>
        <circle class="er-bead" cx="40" cy="32" r="8"/><circle class="er-bead" cx="148" cy="32" r="8"/><circle class="er-hollow" cx="256" cy="32" r="8"/>
    </svg>
@endsection

@section('title', 'This page timed out.')
@section('message', 'A form expires after a while without activity, to protect your account. This attempt was not saved. Go back and send it again.')

@section('actions')
    <a href="{{ url()->previous() === url()->current() ? '/' : url()->previous() }}" class="er-btn"><i aria-hidden="true"></i>Go back and try again</a>
    <a href="/" class="er-link">Go to the start</a>
@endsection
