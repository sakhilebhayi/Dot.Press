<?php

namespace Tests\Feature\Press;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The landing block of the library: what a person is shown when they arrive from InfoDot. One primary action per
 * role, a welcome from real records, the original logo, an honest empty state and no number that was not seeded.
 */
class LandingExperienceTest extends AccessTestCase
{
    /** sha256 of the original artwork. A change here means somebody altered or replaced a logo file. */
    private const LOGOS = [
        'images/logo.png' => '04153cb08606c80a1fcf657ff4943f984399148d0c10a9175cee3b2f1ab513ae',
        'images/logo-light.png' => 'b090c33120b9e04fdaa17013893d889d680dec6fe0dcc8c28032d6e3064a67ed',
    ];

    /** @return array<string, mixed> */
    private function landing(User $user): array
    {
        $response = $this->actingAs($user)->get('/dashboard')->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->component('Press/Library')->has('landing'));

        return $response->viewData('page')['props']['landing'];
    }

    private function path(string $url): string
    {
        return (string) parse_url($url, PHP_URL_PATH).(parse_url($url, PHP_URL_QUERY) ? '?'.parse_url($url, PHP_URL_QUERY) : '');
    }

    public function test_the_welcome_uses_the_persons_first_name_and_their_companys_name(): void
    {
        $landing = $this->landing($this->owner);

        $this->assertSame('Olive', $landing['firstName']);
        $this->assertSame($this->team->name, $landing['company']);

        $this->assertSame('Vera', $this->landing($this->viewer)['firstName']);
    }

    public function test_people_who_may_create_get_new_presentation_as_their_one_primary_action_and_it_answers(): void
    {
        foreach ([$this->owner, $this->admin, $this->editor] as $person) {
            $primary = $this->landing($person)['primary'];

            $this->assertSame('new', $primary['kind']);
            $this->assertSame('New presentation', $primary['label']);
            $this->assertSame('/dashboard?new=1', $this->path($primary['href']));
            $this->actingAs($person)->get($this->path($primary['href']))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('Press/Library')->where('canCreate', true));

            // The workflow behind it is real: the New sheet posts here, then opens the editor on what it made.
            $id = $this->actingAs($person)->postJson('/api/decks', ['title' => 'Made from the landing', 'size' => '16:9'])->assertCreated()->json('id');
            $this->actingAs($person)->get(route('press.editor', ['deck' => $id]))->assertOk();
        }
    }

    public function test_a_viewer_opens_the_presentation_shown_to_them_and_that_page_answers(): void
    {
        $landing = $this->landing($this->viewer);

        $this->assertSame('open', $landing['primary']['kind']);
        $this->assertSame($this->deck->id, $landing['featured']['id']);
        $this->assertSame("/decks/{$this->deck->id}", $this->path($landing['primary']['href']));
        $this->actingAs($this->viewer)->get($this->path($landing['primary']['href']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Press/Editor')->where('role', 'viewer'));
    }

    public function test_a_new_member_with_nothing_gets_an_honest_empty_state(): void
    {
        $fresh = User::factory()->withPersonalTeam()->create(['name' => 'Nomsa Newcomer']);
        $landing = $this->landing($fresh);

        $this->assertSame('Nomsa', $landing['firstName']);
        $this->assertSame($fresh->currentTeam->name, $landing['company']);
        $this->assertNull($landing['featured']);
        $this->assertSame('new', $landing['primary']['kind']);
        $this->actingAs($fresh)->getJson('/api/decks')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_viewer_with_nothing_to_open_is_told_so_and_is_offered_no_action(): void
    {
        $this->deck->forceFill(['archived_at' => now()])->save();
        $landing = $this->landing($this->viewer);

        $this->assertNull($landing['featured']);
        $this->assertNull($landing['primary']);
    }

    public function test_the_presentation_shown_is_the_one_last_opened_and_its_figures_are_the_records(): void
    {
        $older = $this->makeDeck('team', 'Opened last week');
        for ($i = 1; $i <= 3; $i++) {
            $older->slides()->create(['title' => "More {$i}", 'sort_order' => $i, 'els' => []]);
        }
        $this->makeDeck('team', 'Newest, never opened');
        DB::table('deck_user')->insert(['deck_id' => $older->id, 'user_id' => $this->owner->id, 'last_opened_at' => now()->subWeek()]);

        $featured = $this->landing($this->owner)['featured'];

        $this->assertSame($older->id, $featured['id']);
        $this->assertSame('Opened last week', $featured['title']);
        $this->assertSame(4, $featured['slides']);
        $this->assertSame($older->slides()->count(), $featured['slides']);
        $this->assertNotNull($featured['last_opened_at']);
        $this->assertSame($this->editor->name, $featured['owner']);
    }

    public function test_the_landing_carries_no_number_beyond_the_shown_presentations_own_record(): void
    {
        $landing = $this->landing($this->owner);
        $numbers = [];
        array_walk_recursive($landing, function ($value, $key) use (&$numbers): void {
            if (is_int($value) || is_float($value)) {
                $numbers[] = $key;
            }
        });
        // preview elements carry geometry (x, y, w, h); everything else numeric must be one of these record fields
        $allowed = ['id', 'w', 'h', 'slides', 'first_slide', 'x', 'y'];
        $this->assertSame([], array_values(array_diff(array_unique($numbers), $allowed)));
        $this->assertSame($this->deck->slides()->count(), $landing['featured']['slides']);
        $this->assertSame(['firstName', 'company', 'logo', 'infodot', 'featured', 'primary'], array_keys($landing));
    }

    public function test_a_private_or_archived_or_other_teams_deck_is_never_shown(): void
    {
        $this->deck->forceFill(['archived_at' => now()])->save();
        $this->makeDeck('private', 'Private to the editor', $this->editor);

        $this->assertNull($this->landing($this->viewer)['featured']);
        $this->assertNull($this->landing($this->outsider)['featured']);
        $this->assertSame('Private to the editor', $this->landing($this->editor)['featured']['title']);
    }

    public function test_the_logo_files_are_the_original_artwork_and_the_landing_points_at_them(): void
    {
        foreach (self::LOGOS as $file => $hash) {
            $this->assertFileExists(public_path($file));
            $this->assertSame($hash, hash_file('sha256', public_path($file)), "{$file} is not the original artwork");
        }

        $this->assertSame(['light' => '/images/logo.png', 'dark' => '/images/logo-light.png'], $this->landing($this->owner)['logo']);
    }

    public function test_the_way_back_goes_to_infodot_home_and_its_platforms_page(): void
    {
        $this->assertSame(
            ['home' => 'https://infodot.co.za/home', 'platforms' => 'https://infodot.co.za/platforms'],
            $this->landing($this->owner)['infodot'],
        );

        config(['ecosystem.platforms.infodot.url' => 'https://infodot.test/']);
        $this->assertSame('https://infodot.test/platforms', $this->landing($this->owner)['infodot']['platforms']);
    }

    public function test_the_page_marks_exactly_one_primary_action_per_branch_and_no_other_filled_button(): void
    {
        $block = (string) file_get_contents(resource_path('js/press/library/LandingBlock.vue'));
        $page = (string) file_get_contents(resource_path('js/Pages/Press/Library.vue'));
        $layout = (string) file_get_contents(resource_path('js/Layouts/PressLayout.vue'));

        // one marker on each side of a v-if / v-else pair, so one element carries it once the page is drawn
        $this->assertSame(2, substr_count($block, 'data-primary-action'));
        $this->assertMatchesRegularExpression('/<a v-if="primary\.kind === \'new\'"[^>]*data-primary-action/', $block);
        $this->assertMatchesRegularExpression('/<Link v-else[^>]*data-primary-action/', $block);
        $this->assertSame(2, substr_count($block, 'pr-btn--primary'));
        $this->assertStringNotContainsString('data-primary-action', $page.$layout);
        $this->assertStringNotContainsString('pr-btn--primary', $page.$layout);
        $this->assertStringContainsString('>InfoDot</a>', $block);
        $this->assertStringContainsString('>Platforms</a>', $block);
        $this->assertStringContainsString('alt="Dot.Press"', $block);
    }

    public function test_the_platform_list_holds_only_current_platforms(): void
    {
        $current = ['agents', 'analytics', 'auction', 'central', 'charts', 'design', 'docs', 'dopemine', 'ehail', 'emall', 'engage', 'files',
            'finance', 'forms', 'hr', 'memory', 'mines', 'notify', 'press', 'projects', 'pulse', 'sheet', 'tasks', 'tutor', 'infodot'];

        $this->assertSame([], array_values(array_diff(array_keys(config('ecosystem.platforms')), $current)));
        $this->assertSame('Dot.Press', config('ecosystem.platforms.press.name'));
    }
}
