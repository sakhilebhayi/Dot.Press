@php
    $isLanding = ($page['component'] ?? '') === 'Welcome';
    $landingTitle = 'Dot.Press: presentations built as a line of claims';
    $landingDescription = 'Dot.Press is a presentation editor where each slide is a bead on a line, hollow until it shows evidence. Exports say what will change before they run.';
    $home = url('/');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">
        <meta name="theme-color" content="#eef0f1" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="#101214" media="(prefers-color-scheme: dark)">

        {{-- Theme before first paint: the saved choice (press:theme), else the system setting. Storage can be blocked, so every access is guarded. --}}
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
        <style>html{background:#eef0f1}html[data-theme="dark"]{background:#101214}body{margin:0}</style>

        <title inertia>{{ $isLanding ? $landingTitle : config('app.name', 'Dot.Press') }}</title>

        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="icon" href="/favicon.ico" sizes="48x48">
        <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
        <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
        <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
        <link rel="manifest" href="/site.webmanifest">

        {{-- The two interface fonts, self hosted. Nothing is requested from another origin. --}}
        <link rel="preload" href="/fonts/ui/bricolage-300_800-normal.woff2" as="font" type="font/woff2" crossorigin>
        <link rel="preload" href="/fonts/ui/instrument-400_700-normal.woff2" as="font" type="font/woff2" crossorigin>

        @if ($isLanding)
        <meta name="description" content="{{ $landingDescription }}">
        <link rel="canonical" href="{{ $home }}">
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="Dot.Press">
        <meta property="og:title" content="{{ $landingTitle }}">
        <meta property="og:description" content="{{ $landingDescription }}">
        <meta property="og:url" content="{{ $home }}">
        <meta property="og:image" content="{{ url('/og.png') }}">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta property="og:image:alt" content="A Dot.Press deck as a line of beads beside a slide with a revenue chart.">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="{{ $landingTitle }}">
        <meta name="twitter:description" content="{{ $landingDescription }}">
        <meta name="twitter:image" content="{{ url('/og.png') }}">
        <script type="application/ld+json">{!! json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'SoftwareApplication',
            'name' => 'Dot.Press',
            'url' => $home,
            'description' => $landingDescription,
            'applicationCategory' => 'BusinessApplication',
            'operatingSystem' => 'Any, in a web browser',
            'browserRequirements' => 'Requires JavaScript',
            'featureList' => [
                'A presentation as a line of slides, each marked with evidence or without',
                'Export to PowerPoint and PDF with a preflight that lists what changes',
                'Deck review for fonts, colours, density, terminology and unsupported claims',
                'Suggestions that arrive as proposals the author accepts one at a time',
                'Offline present package, presenter console and phone remote',
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
        @endif

        @routes(null, \Illuminate\Support\Facades\Vite::cspNonce())
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body>
        @inertia
        <noscript>
            <p style="font:16px/1.5 system-ui,sans-serif;max-width:36rem;margin:15vh auto;padding:0 20px;color:#14171a">
                Dot.Press is a presentation editor and it needs JavaScript to run. Turn JavaScript on in this browser and reload the page.
            </p>
        </noscript>
    </body>
</html>
