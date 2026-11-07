<?php

namespace Tests\Feature\Press;

use App\Models\Deck;
use App\Models\Project;
use App\Models\Slide;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

trait MakesDecks
{
    protected function owner(): User
    {
        return User::factory()->withPersonalTeam()->create();
    }

    protected function teammate(User $owner, string $role = 'editor'): User
    {
        $user = User::factory()->create();
        $owner->currentTeam->users()->attach($user, ['role' => $role]);
        $user->switchTeam($owner->currentTeam);

        return $user->fresh();
    }

    protected function deckFor(User $owner, string $title = 'Deck', int $slides = 1, ?Team $team = null): Deck
    {
        $team ??= $owner->currentTeam;
        $project = Project::firstOrCreate(
            ['team_id' => $team->id, 'name' => 'Board'],
            ['user_id' => $owner->id, 'slug' => 'board-'.Str::random(5)],
        );

        $deck = Deck::create(['project_id' => $project->id, 'user_id' => $owner->id, 'title' => $title, 'sort_order' => 0, 'theme' => null, 'sections' => []]);
        for ($i = 0; $i < $slides; $i++) {
            $this->slideFor($deck, "Slide {$i}", $i);
        }

        return $deck->fresh();
    }

    /**
     * @param  list<array<string, mixed>>  $els
     */
    protected function slideFor(Deck $deck, string $title = 'Slide', int $order = 0, array $els = []): Slide
    {
        return Slide::create(['deck_id' => $deck->id, 'title' => $title, 'sort_order' => $order, 'els' => $els, 'revision' => 0]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function shape(string $id, array $extra = []): array
    {
        return array_merge(['id' => $id, 'type' => 'shape', 'kind' => 'rect', 'x' => 100, 'y' => 100, 'w' => 200, 'h' => 100, 'fill' => '#2563eb'], $extra);
    }

    /**
     * @param  list<array<string, mixed>>  $ops
     */
    protected function ops(User $user, Deck $deck, array $ops, ?string $key = null, string $client = 'tab-1'): TestResponse
    {
        return $this->actingAs($user)->postJson('/api/decks/'.$deck->id.'/ops', [
            'client' => $client,
            'key' => $key ?? 'k-'.Str::uuid(),
            'ops' => $ops,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function els(Slide $slide): array
    {
        return $slide->fresh()->els ?? [];
    }
}
