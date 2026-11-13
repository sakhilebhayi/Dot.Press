@extends('errors._ecosystem-layout', ['code' => 403])

{{-- R-50: the company does not hold Dot.Press. Shown at sign-in from InfoDot and during a session; no data on it. --}}
@section('rail')
    <svg class="er-rail" viewBox="0 0 640 64" fill="none" role="img" aria-label="A line with an empty place where the next bead would go">
        <line class="er-line" x1="0" y1="40" x2="640" y2="40"/>
        <circle class="er-bead" cx="40" cy="40" r="8"/><circle class="er-bead" cx="148" cy="40" r="8"/><circle class="er-bead" cx="256" cy="40" r="8"/>
        <circle class="er-slot" cx="364" cy="40" r="9"/>
    </svg>
@endsection

@section('title', $notice['title'])
@section('message', $notice['message'])

@section('actions')
    <a href="{{ $platformsUrl }}" class="er-btn"><i aria-hidden="true"></i>Open Platforms in InfoDot</a>
    @if ($others->isNotEmpty() && Route::has('current-team.update'))
    <div class="er-switch">
        <span id="er-switch-l">Or work in another company:</span>
        <ul aria-labelledby="er-switch-l">
            @foreach ($others as $team)
            <li>
                <form method="POST" action="{{ route('current-team.update') }}">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="team_id" value="{{ $team->id }}">
                    <button type="submit" class="er-link">{{ $team->name }}</button>
                </form>
            </li>
            @endforeach
        </ul>
    </div>
    @endif
@endsection
