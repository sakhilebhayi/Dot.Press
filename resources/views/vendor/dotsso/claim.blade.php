{{-- R-54: confirm-it-is-you page, in The Spine. Shown when a Press account already uses the email of the InfoDot sign-in.
     Nothing on that account is replaced; the emailed code proves the address. Variables come from the shared package. --}}
@extends('errors._ecosystem-layout')

@section('doctitle', 'Confirm it is you')
@section('kicker', 'Sign-in')
@section('mainclass', 'er-main--form')

@section('rail')
    <svg class="er-rail" viewBox="0 0 640 64" fill="none" role="img" aria-label="A line with solid beads and one hollow bead waiting to be confirmed">
        <line class="er-line" x1="0" y1="40" x2="640" y2="40"/>
        <circle class="er-bead" cx="40" cy="40" r="8"/><circle class="er-bead" cx="148" cy="40" r="8"/>
        <circle class="er-here" cx="256" cy="40" r="8"/>
        <circle class="er-hollow" cx="364" cy="40" r="8"/><circle class="er-hollow" cx="472" cy="40" r="8"/>
    </svg>
@endsection

@section('title', 'Confirm it is you.')
@section('message', 'A Dot.Press account already uses '.$maskedEmail.'. We sent a '.$codeLength.'-digit code to that address. Enter it to connect the two. Your password and your decks stay as they are.')

@section('body')
    <form method="POST" action="{{ $verifyUrl }}" class="er-form" novalidate>
        @csrf
        @if (session('dotsso_status'))<p class="er-status" role="status">{{ session('dotsso_status') }}</p>@endif
        <label class="er-label" for="code">Code from the email</label>
        <input class="er-input" id="code" name="code" type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code" maxlength="{{ $codeLength }}" required autofocus aria-describedby="code-help code-error" aria-invalid="{{ $errors->has('code') ? 'true' : 'false' }}">
        <p id="code-help" class="er-help">It works for {{ $minutes }} minutes. You have {{ $maxAttempts }} tries.</p>
        <p id="code-error" class="er-error" role="alert">{{ $errors->first('code') }}</p>
        <button type="submit" class="er-btn"><i aria-hidden="true"></i>Confirm and continue</button>
    </form>
    <div class="er-aside">
        <form method="POST" action="{{ $resendUrl }}">@csrf<button type="submit" class="er-quiet">Send a new code</button></form>
        <form method="POST" action="{{ $cancelUrl }}">@csrf<button type="submit" class="er-quiet">Cancel</button></form>
    </div>
@endsection
