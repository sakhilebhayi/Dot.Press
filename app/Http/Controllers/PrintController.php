<?php

namespace App\Http\Controllers;

use App\Models\Deck;
use App\Services\Press\PresentPayload;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The print view of a deck: every slide in order, one per page, drawn by the same renderer as the editor so that the
 * browser's "Save as PDF" gives selectable text and the exact fonts. Anyone who may view the deck may print it.
 *
 *   ?hidden=1   include hidden slides (left out by default, and not even sent to the page when left out)
 *   ?notes=1    add a notes page after each slide that has speaker notes
 */
class PrintController extends Controller
{
    /** Signed picture URLs in the page stay valid for this many hours (a long print session, not a share link). */
    private const ASSET_HOURS = 3;

    public function show(Request $request, Deck $deck): Response
    {
        $this->authorize('view', $deck);

        $hidden = $request->boolean('hidden');
        $notes = $request->boolean('notes');

        $slides = array_values(array_filter(
            PresentPayload::slides($deck, notes: $notes),
            fn (array $slide): bool => $hidden || ! $slide['hidden'],
        ));

        return Inertia::render('Press/Print', [
            'deck' => PresentPayload::deckHeader($deck),
            'slides' => $slides,
            'assets' => PresentPayload::assetMap($deck, $slides, now()->addHours(self::ASSET_HOURS)),
            'options' => ['hidden' => $hidden, 'notes' => $notes],
            'backUrl' => route('press.editor', $deck, absolute: false),
        ]);
    }
}
