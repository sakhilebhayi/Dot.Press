<?php

namespace Tests\Feature\Press;

use App\Models\DeckPresence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PresenceTest extends TestCase
{
    use MakesDecks;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function beat($user, $deck, string $client, array $body = [])
    {
        return $this->actingAs($user)->postJson("/api/decks/{$deck->id}/presence", ['client' => $client] + $body);
    }

    public function test_a_tab_sees_the_others_but_not_itself_and_gets_the_current_seq(): void
    {
        $user = $this->owner();
        $other = $this->teammate($user);
        $deck = $this->deckFor($user);
        $slide = $deck->slides()->first();

        $this->beat($user, $deck, 'tab-a', ['slide' => $slide->id, 'selection' => ['x1', 'x2'], 'cursor' => ['x' => 10.46, 'y' => 20]])->assertOk()->assertJsonPath('others', []);
        $seq = $this->ops($user, $deck, [['op' => 'deck.set', 'title' => 'T']])->json('seq');

        $response = $this->beat($other, $deck, 'tab-b')->assertOk();
        $response->assertJsonPath('seq', $seq)->assertJsonCount(1, 'others');
        $seen = $response->json('others.0');
        $this->assertSame('tab-a', $seen['client']);
        $this->assertSame($user->id, $seen['user_id']);
        $this->assertSame($user->name, $seen['name']);
        $this->assertSame($slide->id, $seen['slide']);
        $this->assertSame(['x1', 'x2'], $seen['selection']);
        $this->assertSame(['x' => 10.5, 'y' => 20], $seen['cursor']);

        // The first tab now sees the second; a second beat from it updates rather than duplicates the row.
        $this->beat($user, $deck, 'tab-a')->assertJsonCount(1, 'others');
        $this->assertSame(2, DeckPresence::count());
    }

    public function test_a_person_gets_the_same_colour_every_time(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user);

        $this->beat($user, $deck, 'one');
        $first = DeckPresence::first()->color;
        $this->beat($user, $deck, 'two');

        $this->assertSame([$first, $first], DeckPresence::pluck('color')->all());
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $first);
    }

    public function test_tabs_unseen_for_20_seconds_are_hidden_and_after_2_minutes_deleted(): void
    {
        Carbon::setTestNow('2026-11-08 10:00:00');
        $user = $this->owner();
        $other = $this->teammate($user);
        $deck = $this->deckFor($user);
        $this->beat($user, $deck, 'gone');

        Carbon::setTestNow('2026-11-08 10:00:19');
        $this->beat($other, $deck, 'me')->assertJsonCount(1, 'others');

        Carbon::setTestNow('2026-11-08 10:00:21');
        $this->beat($other, $deck, 'me')->assertJsonCount(0, 'others');
        $this->assertSame(2, DeckPresence::count());

        Carbon::setTestNow('2026-11-08 10:02:30');
        $this->beat($other, $deck, 'me');
        $this->assertSame(['me'], DeckPresence::pluck('client')->all());
    }

    public function test_input_is_validated_and_a_foreign_slide_is_ignored(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor($user, 'Other');

        $this->beat($user, $deck, 'bad client!')->assertStatus(422);
        $this->beat($user, $deck, 'c', ['selection' => ['<script>']])->assertStatus(422);
        $this->beat($user, $deck, 'c', ['selection' => array_fill(0, 51, 'a')])->assertStatus(422);
        $this->beat($user, $deck, 'c', ['cursor' => ['x' => 1]])->assertStatus(422);

        $this->beat($user, $deck, 'c', ['slide' => $otherDeck->slides()->first()->id])->assertOk();
        $this->assertNull(DeckPresence::first()->slide_id);
    }

    public function test_a_client_id_cannot_be_taken_over_by_someone_else(): void
    {
        $user = $this->owner();
        $other = $this->teammate($user);
        $deck = $this->deckFor($user);

        $this->beat($user, $deck, 'tab-1');
        $this->beat($other, $deck, 'tab-1')->assertStatus(409);
        $this->assertSame($user->id, DeckPresence::first()->user_id);
    }

    public function test_presence_stays_inside_the_team(): void
    {
        $user = $this->owner();
        $stranger = $this->owner();
        $deck = $this->deckFor($user);

        $this->beat($stranger, $deck, 'tab-x')->assertNotFound();
        $this->assertSame(0, DeckPresence::count());
    }

    public function test_a_reloaded_tab_replaces_its_own_earlier_row_and_never_someone_elses(): void
    {
        $user = $this->owner();
        $other = $this->teammate($user);
        $deck = $this->deckFor($user);

        $this->beat($user, $deck, 'before-reload')->assertOk();
        $this->beat($other, $deck, 'their-tab')->assertOk();
        $this->assertSame(2, DeckPresence::count());

        $this->beat($user, $deck, 'after-reload', ['replaces' => 'before-reload'])->assertOk()->assertJsonCount(1, 'others');
        $this->assertSame(['after-reload', 'their-tab'], DeckPresence::orderBy('id')->pluck('client')->sort()->values()->all());

        // naming someone else's client id removes nothing
        $this->beat($user, $deck, 'after-reload', ['replaces' => 'their-tab'])->assertOk();
        $this->assertSame(2, DeckPresence::count());
        $this->assertTrue(DeckPresence::where('client', 'their-tab')->exists());
    }
}
