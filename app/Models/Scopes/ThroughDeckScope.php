<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * A row that belongs to a deck (slide, comment, review, share, activity) is visible only when its deck is, which
 * runs the deck's own DeckAccessScope inside the subquery. A private deck's children are therefore hidden from
 * everyone who cannot see the deck, in any query.
 */
class ThroughDeckScope implements Scope
{
    public function __construct(private readonly string $relation = 'deck') {}

    public function apply(Builder $builder, Model $model): void
    {
        if (DeckAccessScope::bypassed() || ! Auth::check()) {
            return;
        }

        $builder->whereHas($this->relation);
    }
}
