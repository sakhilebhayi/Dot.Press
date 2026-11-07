<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">
        <meta name="theme-color" content="#eef0f1" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="#101214" media="(prefers-color-scheme: dark)">
        <meta name="robots" content="noindex">
        <title>{{ $code }}: @yield('title') · Dot.Press</title>

        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="icon" href="/favicon.ico" sizes="48x48">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        {{-- The theme before first paint, as on every page. Storage can be blocked, so it is guarded. --}}
        <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
            (function () {
                var root = document.documentElement, mode = null;
                try { mode = localStorage.getItem('press:theme'); } catch (e) {}
                if (mode !== 'light' && mode !== 'dark') {
                    mode = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                }
                root.setAttribute('data-theme', mode);
                root.style.colorScheme = mode;
            })();
        </script>

        {{-- Everything this page needs is inline, so it renders even when the build assets cannot be loaded. --}}
        <style>
            {!! file_get_contents(resource_path('css/press/tokens.css')) !!}
            {!! file_get_contents(resource_path('css/press/fonts-ui.css')) !!}
            {!! file_get_contents(resource_path('css/press/errors.css')) !!}
        </style>
    </head>
    <body>
        <header class="er-top">
            <a href="/" class="er-home" aria-label="Dot.Press home">
                <svg viewBox="0 0 32 12" fill="none" aria-hidden="true"><line x1="0" y1="6" x2="32" y2="6" stroke="currentColor" stroke-width="1.5"/><circle cx="9" cy="6" r="4.25" fill="var(--amber)" stroke="currentColor" stroke-width="1.5"/></svg>
                Dot.Press
            </a>
        </header>

        <main class="er-main" id="main">
            @yield('rail')
            <p class="er-code">error {{ $code }}</p>
            <h1 class="er-title">@yield('title')</h1>
            <p class="er-msg">@yield('message')</p>
            <div class="er-actions">
                @yield('actions')
            </div>
        </main>

        <footer class="er-foot">
            <span>Dot.Press is part of the Dot ecosystem.</span>
            <nav aria-label="Legal">
                @if (Route::has('terms.show'))<a href="{{ route('terms.show') }}">Terms</a>@endif
                @if (Route::has('policy.show'))<a href="{{ route('policy.show') }}">Privacy</a>@endif
                @if (Route::has('cookies'))<a href="{{ route('cookies') }}">Cookies</a>@endif
            </nav>
        </footer>
    </body>
</html>
