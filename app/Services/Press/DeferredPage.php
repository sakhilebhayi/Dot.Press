<?php

namespace App\Services\Press;

use Inertia\Inertia;
use Inertia\Response;

/**
 * A page that needs the whole deck, for a deck too big to write into the page (press_perf.inline_bytes). Building an
 * Inertia page from an 8 MB document takes more than 120 MB of PHP memory, so a big deck is sent as a small page that
 * names the real page (`target`), its own props and a streaming URL for the rest. Pages/Press/Deferred.vue fetches the
 * payload, merges it into the props and mounts the target page, loading the target's code in parallel with the data.
 */
final class DeferredPage
{
    /**
     * @param  string  $target  a page under Pages/Press, for example "Editor"
     * @param  array<string, mixed>  $props  the target page's props except the payload
     * @param  string|null  $payloadKey  the prop the whole payload becomes (the editor's `document`); null merges its keys into the props
     */
    public static function render(string $target, array $props, string $payloadUrl, ?string $payloadKey = null): Response
    {
        return Inertia::render('Press/Deferred', [
            'target' => $target,
            'targetProps' => (object) $props,
            'payloadUrl' => $payloadUrl,
            'payloadKey' => $payloadKey,
        ]);
    }
}
