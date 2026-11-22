{{-- R-54: every sign-in failure ends here, in words, with one next step and no automatic redirect. --}}
@extends('errors._ecosystem-layout')

@section('doctitle', $headline)
@section('kicker', 'Sign-in')
@section('mainclass', 'er-main--form')

@section('rail')
    <svg class="er-rail" viewBox="0 0 640 64" fill="none" role="img" aria-label="A line of solid beads with one hollow bead standing apart from the line">
        <line class="er-line" x1="0" y1="40" x2="640" y2="40"/>
        <circle class="er-bead" cx="40" cy="40" r="8"/><circle class="er-bead" cx="148" cy="40" r="8"/><circle class="er-bead" cx="256" cy="40" r="8"/>
        <circle class="er-hollow" cx="364" cy="10" r="8"/>
    </svg>
@endsection

@section('title', $headline)
@section('message', $body)

@section('actions')
    @if ($retry)<a href="{{ $retryUrl }}" class="er-btn"><i aria-hidden="true"></i>Start again</a>@endif
    <a href="{{ $homeUrl }}" class="{{ $retry ? 'er-link' : 'er-btn' }}">@unless($retry)<i aria-hidden="true"></i>@endunless Back to Dot.Press</a>
@endsection

@section('body')
    <p class="er-ref">Reference: {{ $code }}</p>
@endsection
