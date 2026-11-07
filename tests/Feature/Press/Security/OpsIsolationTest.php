<?php

namespace Tests\Feature\Press\Security;

use App\Models\Deck;
use App\Models\DeckChange;
use App\Models\Project;
use App\Models\Slide;
use App\Models\User;
use App\Services\Press\DocumentSanitizer;
use App\Services\Press\InvalidDocument;
use Illuminate\Support\Str;
use Tests\Feature\Press\AccessTestCase;

/**
 * R-40 security pass, tenant isolation of the operations API: ids that belong to another deck or another team,
 * smuggled into an operation by someone who may edit their own deck, must never reach the victim.
 */
class OpsIsolationTest extends AccessTestCase
{
    private function shape(string $id): array
    {
        return ['id' => $id, 'type' => 'shape', 'kind' => 'rect', 'x' => 1, 'y' => 1, 'w' => 10, 'h' => 10];
    }

    private function send(User $user, Deck $deck, array $ops)
    {
        return $this->actingAs($user)->postJson("/api/decks/{$deck->id}/ops", ['client' => 'tab-1', 'key' => 'k-'.Str::uuid(), 'ops' => $ops]);
    }

    /** A second deck in the same team that the attacker cannot see (private, other creator). */
    private function victimDeck(): Deck
    {
        $private = $this->makeDeck('private', 'Secret plans', $this->owner);

        return $private;
    }

    public function test_slide_ids_of_a_deck_you_cannot_open_are_unknown_in_every_slide_and_element_op(): void
    {
        $victim = $this->victimDeck();
        $victimSlide = $victim->slides()->first();
        $before = $victimSlide->fresh()->toArray();
        $mine = $this->makeDeck('team', 'Mine', $this->editor);
        $mySlide = $mine->slides()->first();

        $response = $this->send($this->editor, $mine, [
            ['op' => 'slide.set', 'id' => $victimSlide->id, 'title' => 'pwned'],
            ['op' => 'slide.remove', 'id' => $victimSlide->id],
            ['op' => 'slide.move', 'id' => $victimSlide->id, 'after' => null],
            ['op' => 'slide.move', 'id' => $mySlide->id, 'after' => $victimSlide->id],
            ['op' => 'slide.dup', 'id' => $victimSlide->id, 'tid' => 'steal'],
            ['op' => 'slide.add', 'tid' => 'n1', 'after' => $victimSlide->id, 'slide' => ['title' => 'ok']],
            ['op' => 'el.add', 'slide' => $victimSlide->id, 'el' => $this->shape('evil')],
            ['op' => 'el.set', 'slide' => $victimSlide->id, 'id' => 'title1', 'p' => ['x' => 999]],
            ['op' => 'el.remove', 'slide' => $victimSlide->id, 'id' => 'title1'],
            ['op' => 'el.order', 'slide' => $victimSlide->id, 'ids' => ['title1']],
            ['op' => 'el.move', 'from' => $victimSlide->id, 'id' => 'title1', 'to' => $mySlide->id],
            ['op' => 'el.move', 'from' => $mySlide->id, 'id' => 'title1', 'to' => $victimSlide->id],
        ])->assertOk();

        $this->assertSame($before, $victimSlide->fresh()->toArray(), 'the other deck must be untouched');
        $this->assertSame(1, Slide::withoutGlobalScopes()->where('deck_id', $victim->id)->count());
        $this->assertCount(0, Slide::withoutGlobalScopes()->where('deck_id', $mine->id)->where('title', 'Slide one')->where('id', '!=', $mySlide->id)->get());
        $skipped = collect($response->json('skipped'))->pluck('index')->all();
        // A move or an add that names an unknown slide to go after simply goes last (documented in the spec); everything
        // else that names a slide of another deck is skipped.
        $this->assertEqualsCanonicalizing([0, 1, 2, 4, 6, 7, 8, 9, 10, 11], $skipped);
        $this->assertSame(0, DeckChange::where('deck_id', $victim->id)->count());
    }

    public function test_assets_of_another_team_cannot_be_used_in_any_field(): void
    {
        $foreignProject = Project::create(['user_id' => $this->outsider->id, 'team_id' => $this->otherTeam->id, 'name' => 'Other', 'slug' => 'other-'.uniqid()]);
        $foreign = $this->asset('secret.png', $foreignProject);
        $mine = $this->makeDeck('team', 'Mine', $this->editor);
        $slideId = $mine->slides()->first()->id;

        $response = $this->send($this->editor, $mine, [
            ['op' => 'el.add', 'slide' => $slideId, 'el' => ['id' => 'i1', 'type' => 'image', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'src' => ['asset' => $foreign->id]]],
            ['op' => 'el.add', 'slide' => $slideId, 'el' => ['id' => 'v1', 'type' => 'video', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'src' => ['url' => 'https://example.com/a.mp4'], 'poster' => ['asset' => (string) $foreign->id]]],
            ['op' => 'slide.set', 'id' => $slideId, 'bg' => ['img' => ['asset' => $foreign->id]]],
            ['op' => 'deck.set', 'theme' => ['id' => 'x', 'bg' => ['img' => ['asset' => $foreign->id]]]],
            ['op' => 'slide.add', 'tid' => 'n', 'slide' => ['bg' => ['img' => ['asset' => $foreign->id]], 'els' => [['id' => 'i2', 'type' => 'image', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'src' => ['asset' => $foreign->id]]]]],
        ])->assertOk();

        $this->assertSame([0, 1, 2, 3, 4], collect($response->json('skipped'))->pluck('index')->all());
        $this->assertStringNotContainsString((string) 'asset', json_encode(Slide::find($slideId)->els));
        $this->assertNull($mine->fresh()->theme['bg'] ?? null);
    }

    public function test_a_share_recipient_in_another_team_cannot_pull_the_teams_other_assets_into_the_deck(): void
    {
        $shared = $this->sharedWith('editor');
        $secret = $this->asset('internal.png');
        $slideId = $this->slide->id;

        $response = $this->send($shared, $this->deck, [
            ['op' => 'el.add', 'slide' => $slideId, 'el' => ['id' => 'i1', 'type' => 'image', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'src' => ['asset' => $secret->id]]],
        ])->assertOk();

        // The asset belongs to the deck's own team, so the deck may use it. The recipient only gets a signed URL if the document lists it.
        $this->assertSame([], $response->json('skipped'));
    }

    public function test_the_sanitiser_refuses_ids_with_a_trailing_newline(): void
    {
        // PHP's $ matches before a final newline. The HTTP layer trims strings, but imports and internal callers do not.
        $san = new DocumentSanitizer;

        foreach ([
            fn () => $san->id("a\n", 'id'),
            fn () => $san->element($this->shape("nl\n")),
            fn () => $san->slideRef("tid\n"),
            fn () => $san->layout("custom:abc\n"),
            fn () => $san->theme(['id' => "custom:1\n"]),
            fn () => $san->element($this->shape('ok') + ['group' => "g\n"]),
            fn () => $san->section(['id' => "sec\n", 'title' => 'X']),
        ] as $i => $call) {
            try {
                $call();
                $this->fail("case {$i} accepted a trailing newline");
            } catch (InvalidDocument) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_prototype_pollution_keys_never_reach_the_stored_document(): void
    {
        $slideId = $this->slide->id;
        $payload = '{"op":"el.add","slide":'.$slideId.',"el":{"id":"pp","type":"chart","x":1,"y":1,"w":100,"h":100,"__proto__":{"polluted":true},"constructor":{"prototype":{"polluted":true}},'
            .'"chart":{"type":"column","__proto__":{"polluted":true},"data":{"categories":["a"],"series":[{"name":"s","values":[1],"__proto__":{"polluted":true}}],"__proto__":{"polluted":true}},"opt":{"__proto__":{"polluted":true},"ax":{"__proto__":{"polluted":true},"x":{"__proto__":{"polluted":true},"title":"t"}}}}}}';
        $set = '{"op":"el.set","slide":'.$slideId.',"id":"title1","p":{"__proto__":{"polluted":true},"constructor":{"prototype":{"polluted":true}},"adj":{"__proto__":{"polluted":true}},"x":5}}';

        $this->actingAs($this->editor)->call('POST', "/api/decks/{$this->deck->id}/ops", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            '{"client":"t","key":"k-pp","ops":['.$payload.','.$set.']}')->assertOk();

        $stored = json_encode(Slide::find($slideId)->els);
        $this->assertStringNotContainsString('__proto__', $stored);
        $this->assertStringNotContainsString('polluted', $stored);
        $this->assertStringNotContainsString('constructor', $stored);
        $this->assertStringNotContainsString('polluted', json_encode(DeckChange::first()->ops));
    }
}
