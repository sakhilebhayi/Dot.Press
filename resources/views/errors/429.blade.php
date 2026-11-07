@extends('errors._ecosystem-layout', ['code' => 429])

@section('rail')
    <svg class="er-rail" viewBox="0 0 640 64" fill="none" role="img" aria-label="Many beads crowded together at the start of a long empty line">
        <line class="er-line" x1="0" y1="32" x2="640" y2="32"/>
        <circle class="er-bead" cx="24" cy="32" r="8"/><circle class="er-bead" cx="40" cy="32" r="8"/><circle class="er-bead" cx="56" cy="32" r="8"/>
        <circle class="er-bead" cx="72" cy="32" r="8"/><circle class="er-bead" cx="88" cy="32" r="8"/><circle class="er-here" cx="104" cy="32" r="8"/>
    </svg>
@endsection

@section('title', 'Slow down a little.')
@section('message', 'Too many requests came from this connection in a short time. Wait a minute, then try again.')

@section('actions')
    <a href="/" class="er-btn"><i aria-hidden="true"></i>Go to the start</a>
@endsection
