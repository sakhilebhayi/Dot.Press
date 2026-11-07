<?php

namespace App\Models\Concerns;

use App\Models\Scopes\DeckAccessScope;

/** For Deck itself: see DeckAccessScope. */
trait HasDeckAccessScope
{
    protected static function bootHasDeckAccessScope(): void
    {
        static::addGlobalScope('user', new DeckAccessScope);
    }
}
