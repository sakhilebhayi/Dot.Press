<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Slide;
use App\Services\Press\DeckDocument;
use Illuminate\Http\JsonResponse;

/**
 * Slides are read here and changed only through operations on the deck (POST /api/decks/{deck}/ops).
 */
class SlideController extends Controller
{
    /**
     * One slide as it appears in the document (spec section 5), with the size of the deck it sits in.
     */
    public function show(Slide $slide): JsonResponse
    {
        $this->authorize('view', $slide);

        $slide->loadMissing('deck');

        return response()->json(DeckDocument::slideToArray($slide) + [
            'deck_id' => $slide->deck_id,
            'deck' => [
                'id' => $slide->deck->id,
                'title' => $slide->deck->title,
                'w' => (int) $slide->deck->w,
                'h' => (int) $slide->deck->h,
                'rev' => (int) $slide->deck->rev,
            ],
        ]);
    }
}
