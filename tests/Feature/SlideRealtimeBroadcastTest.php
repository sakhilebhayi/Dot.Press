<?php

namespace Tests\Feature;

use App\Broadcasting\SlideChannelAuthorizer;
use App\Events\SlideElementsUpdated;
use App\Models\Deck;
use App\Models\Project;
use App\Models\Slide;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SlideRealtimeBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_canvas_elements_broadcasts_to_the_slides_private_channel(): void
    {
        Event::fake([SlideElementsUpdated::class]);

        $user = User::factory()->withPersonalTeam()->create();

        $project = Project::create([
            'user_id' => $user->id,
            'team_id' => $user->currentTeam->id,
            'name' => 'Realtime Project',
            'slug' => 'realtime-project',
        ]);

        $deck = Deck::create(['project_id' => $project->id, 'title' => 'Deck 1', 'sort_order' => 0]);

        $slide = Slide::create([
            'deck_id' => $deck->id,
            'title' => 'Slide 1',
            'sort_order' => 0,
            'canvas_state' => ['elements' => []],
        ]);

        $this->actingAs($user)
            ->putJson('/api/slides/'.$slide->id, [
                'canvas_state' => [
                    'elements' => [
                        ['id' => 'el-1', 'type' => 'rect', 'x' => 10, 'y' => 10],
                    ],
                ],
            ])
            ->assertOk();

        Event::assertDispatched(SlideElementsUpdated::class, function (SlideElementsUpdated $event) use ($slide, $user) {
            return $event->slide->id === $slide->id
                && $event->editedByUserId === $user->id
                && $event->broadcastOn()[0]->name === 'private-slide.'.$slide->id;
        });
    }

    public function test_saving_without_a_canvas_state_change_does_not_broadcast(): void
    {
        Event::fake([SlideElementsUpdated::class]);

        $user = User::factory()->withPersonalTeam()->create();

        $project = Project::create([
            'user_id' => $user->id,
            'team_id' => $user->currentTeam->id,
            'name' => 'Realtime Project',
            'slug' => 'realtime-project-2',
        ]);

        $deck = Deck::create(['project_id' => $project->id, 'title' => 'Deck 1', 'sort_order' => 0]);

        $slide = Slide::create([
            'deck_id' => $deck->id,
            'title' => 'Slide 1',
            'sort_order' => 0,
            'canvas_state' => ['elements' => []],
        ]);

        $this->actingAs($user)
            ->putJson('/api/slides/'.$slide->id, ['title' => 'Renamed'])
            ->assertOk();

        Event::assertNotDispatched(SlideElementsUpdated::class);
    }

    public function test_with_the_null_connection_a_save_queues_no_broadcast_job(): void
    {
        // Production has no websocket server (BROADCAST_CONNECTION=null).
        // Without this, every canvas save would still insert a BroadcastEvent
        // row into the database queue for cron's queue:work to pick up and
        // throw away -- and fail if the slide was deleted in between.
        Queue::fake();
        config(['broadcasting.default' => 'null']);

        $this->saveCanvasElements();

        Queue::assertNotPushed(BroadcastEvent::class);
    }

    public function test_with_a_real_connection_a_save_queues_the_broadcast_job(): void
    {
        Queue::fake();
        config(['broadcasting.default' => 'log']);

        $this->saveCanvasElements();

        Queue::assertPushed(BroadcastEvent::class);
    }

    public function test_the_broadcast_connection_defaults_to_null_without_an_environment(): void
    {
        $saved = [$_ENV['BROADCAST_CONNECTION'] ?? null, $_SERVER['BROADCAST_CONNECTION'] ?? null, getenv('BROADCAST_CONNECTION')];
        unset($_ENV['BROADCAST_CONNECTION'], $_SERVER['BROADCAST_CONNECTION']);
        putenv('BROADCAST_CONNECTION');

        try {
            $this->assertSame('null', (require config_path('broadcasting.php'))['default']);
        } finally {
            if ($saved[0] !== null) {
                $_ENV['BROADCAST_CONNECTION'] = $saved[0];
            }
            if ($saved[1] !== null) {
                $_SERVER['BROADCAST_CONNECTION'] = $saved[1];
            }
            if ($saved[2] !== false) {
                putenv('BROADCAST_CONNECTION='.$saved[2]);
            }
        }
    }

    private function saveCanvasElements(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $project = Project::create([
            'user_id' => $user->id,
            'team_id' => $user->currentTeam->id,
            'name' => 'Realtime Project',
            'slug' => 'realtime-project-q',
        ]);

        $deck = Deck::create(['project_id' => $project->id, 'title' => 'Deck 1', 'sort_order' => 0]);

        $slide = Slide::create([
            'deck_id' => $deck->id,
            'title' => 'Slide 1',
            'sort_order' => 0,
            'canvas_state' => ['elements' => []],
        ]);

        $this->actingAs($user)
            ->putJson('/api/slides/'.$slide->id, [
                'canvas_state' => ['elements' => [['id' => 'el-1', 'type' => 'rect', 'x' => 10, 'y' => 10]]],
            ])
            ->assertOk();
    }

    public function test_only_a_team_member_can_authorize_the_slide_channel(): void
    {
        // Exercised directly against SlideChannelAuthorizer rather than
        // through POST /broadcasting/auth: the "null"/"log" broadcast
        // drivers used here (see phpunit.xml) never invoke a channel's
        // callback at all, so the HTTP endpoint can't observe this logic.
        $owner = User::factory()->withPersonalTeam()->create();
        $teammate = User::factory()->create();
        $owner->currentTeam->users()->attach($teammate, ['role' => 'editor']);
        $teammate->switchTeam($owner->currentTeam);
        $outsider = User::factory()->withPersonalTeam()->create();

        $project = Project::create([
            'user_id' => $owner->id,
            'team_id' => $owner->currentTeam->id,
            'name' => 'Realtime Project',
            'slug' => 'realtime-project-3',
        ]);

        $deck = Deck::create(['project_id' => $project->id, 'title' => 'Deck 1', 'sort_order' => 0]);

        $slide = Slide::create([
            'deck_id' => $deck->id,
            'title' => 'Slide 1',
            'sort_order' => 0,
            'canvas_state' => ['elements' => []],
        ]);

        $this->assertTrue(SlideChannelAuthorizer::authorize($owner, $slide->id));
        $this->assertTrue(SlideChannelAuthorizer::authorize($teammate, $slide->id));
        $this->assertFalse(SlideChannelAuthorizer::authorize($outsider, $slide->id));
        $this->assertFalse(SlideChannelAuthorizer::authorize($owner, $slide->id + 999));
    }
}
