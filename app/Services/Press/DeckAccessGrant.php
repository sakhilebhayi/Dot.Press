<?php

namespace App\Services\Press;

/**
 * What one person may do with one deck: the role they hold and where it came from.
 * A read-only team member (`viewer`) may comment, because commenting is not a write to the document, while a person
 * who was merely given the deck as a `viewer` may only look.
 */
final class DeckAccessGrant
{
    public function __construct(
        public readonly string $role,
        public readonly bool $viaTeamViewer = false,
    ) {}

    public function rank(): int
    {
        return DeckAccess::RANK[$this->role];
    }

    public function can(string $ability): bool
    {
        return match ($ability) {
            'view', 'present', 'export' => true,
            'comment' => $this->rank() >= DeckAccess::RANK['commenter'] || $this->viaTeamViewer,
            'update', 'rename' => $this->rank() >= DeckAccess::RANK['editor'],
            'manage' => $this->role === 'owner',
            default => false,
        };
    }

    /** @return list<string> */
    public function abilities(): array
    {
        return array_values(array_filter(DeckAccess::ABILITIES, fn (string $ability) => $this->can($ability)));
    }
}
