<?php

namespace App\Services\Press;

use App\Models\Deck;
use App\Models\Scopes\DeckAccessScope;
use App\Models\Slide;

/** Where an e-mail or notification should send a person to open a deck. */
class DeckLinks
{
    public static function editor(Deck $deck, ?int $slideId = null): string
    {
        return DeckAccessScope::without(function () use ($deck, $slideId): string {
            $slide = $slideId
                ? Slide::where('deck_id', $deck->id)->whereKey($slideId)->first()
                : null;
            $slide ??= Slide::where('deck_id', $deck->id)->orderBy('sort_order')->orderBy('id')->first();

            return $slide
                ? route('editor.slides.show', [$deck->id, $slide->id])
                : route('dashboard');
        });
    }
}
