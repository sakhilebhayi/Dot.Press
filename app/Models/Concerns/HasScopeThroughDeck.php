<?php

namespace App\Models\Concerns;

use App\Models\Scopes\ThroughDeckScope;

/** For every row that belongs to a deck: it is visible only when its deck is. See ThroughDeckScope. */
trait HasScopeThroughDeck
{
    protected static function bootHasScopeThroughDeck(): void
    {
        static::addGlobalScope('deck-access', new ThroughDeckScope('deck'));
    }
}
