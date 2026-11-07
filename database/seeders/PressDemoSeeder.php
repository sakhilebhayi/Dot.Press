<?php

namespace Database\Seeders;

use App\Models\Deck;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Services\Press\ThemeLibrary;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Local demo data (never runs in production): one team with an owner, an editor and a viewer, and two decks that
 * use every element type, so the editor, the player and the exporters can be looked at. Logins use the password
 * `password`: owner@demo.test, editor@demo.test, viewer@demo.test.
 */
class PressDemoSeeder extends Seeder
{
    public function run(): void
    {
        abort_if(app()->environment('production'), 403, 'The demo seeder never runs in production.');

        $owner = User::factory()->withPersonalTeam()->create(['name' => 'Owner Demo', 'email' => 'owner@demo.test', 'password' => Hash::make('password')]);
        $team = $owner->currentTeam;
        foreach (['editor' => 'Edith Editor', 'viewer' => 'Vic Viewer'] as $role => $name) {
            $user = User::factory()->withPersonalTeam()->create(['name' => $name, 'email' => "{$role}@demo.test", 'password' => Hash::make('password')]);
            $team->users()->attach($user, ['role' => $role]);
            $user->forceFill(['current_team_id' => $team->id])->save();
        }

        $project = Project::create(['user_id' => $owner->id, 'team_id' => $team->id, 'name' => 'Dot.Press Workspace', 'slug' => 'dot-press-workspace']);
        $theme = ThemeLibrary::find('slate');

        $this->deck($project, $owner, $theme, 'Q3 mining production review', $this->miningSlides());
        $this->deck($project, $owner, $theme, 'Investor pitch (draft)', $this->pitchSlides());
    }

    /** @param  list<array<string, mixed>>  $slides */
    private function deck(Project $project, User $owner, array $theme, string $title, array $slides): Deck
    {
        $deck = Deck::create(['project_id' => $project->id, 'user_id' => $owner->id, 'title' => $title, 'theme' => $theme, 'w' => 1920, 'h' => 1080, 'sections' => [['id' => 'sec1', 'title' => 'Opening'], ['id' => 'sec2', 'title' => 'Evidence']]]);
        foreach ($slides as $i => $slide) {
            $deck->slides()->create([
                'title' => $slide['title'], 'notes' => $slide['notes'] ?? null, 'layout' => $slide['layout'] ?? 'blank', 'sort_order' => $i,
                'section_id' => $i < 2 ? 'sec1' : 'sec2', 'els' => $slide['els'], 'bg' => $slide['bg'] ?? null, 'transition' => $slide['transition'] ?? null,
            ]);
        }

        return $deck;
    }

    /** @return array<string, mixed> */
    private function text(string $id, string $text, array $box, array $p = []): array
    {
        $paras = array_map(fn (string $line) => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $line]]], explode("\n", $text));

        return array_merge(['id' => $id, 'type' => 'text', 'x' => $box[0], 'y' => $box[1], 'w' => $box[2], 'h' => $box[3], 'doc' => ['type' => 'doc', 'content' => $paras],
            'font' => '@body', 'size' => 40, 'weight' => 400, 'color' => '@ink', 'align' => 'left', 'valign' => 'top', 'lh' => 1.3], $p);
    }

    /** @return list<array<string, mixed>> */
    private function miningSlides(): array
    {
        return [
            ['title' => 'Q3 production beat plan by 4 percent', 'layout' => 'title', 'notes' => 'Open with the headline number, then the two drivers.', 'transition' => ['type' => 'fade', 'dir' => 'r', 'dur' => 500],
                'els' => [
                    $this->text('t1', 'Q3 production beat plan by 4 percent', [140, 300, 1500, 300], ['font' => '@heading', 'size' => 110, 'weight' => 700, 'lh' => 1.02, 'role' => 'title']),
                    $this->text('t2', 'Mining operations review, Q3 2026', [140, 640, 1200, 80], ['size' => 44, 'color' => '@muted', 'role' => 'subtitle']),
                    ['id' => 's1', 'type' => 'shape', 'kind' => 'rect', 'x' => 140, 'y' => 250, 'w' => 160, 'h' => 12, 'fill' => '@accent', 'stroke' => null, 'sw' => 0],
                ]],
            ['title' => 'Three things drove the result', 'layout' => 'bullets', 'els' => [
                $this->text('h', 'Three things drove the result', [140, 100, 1600, 140], ['font' => '@heading', 'size' => 76, 'weight' => 700, 'role' => 'title']),
                ['id' => 'b', 'type' => 'text', 'x' => 140, 'y' => 300, 'w' => 1500, 'h' => 600, 'role' => 'body', 'font' => '@body', 'size' => 48, 'weight' => 400, 'color' => '@ink', 'align' => 'left', 'valign' => 'top', 'lh' => 1.4, 'ps' => 0.4,
                    'doc' => ['type' => 'doc', 'content' => [['type' => 'bulletList', 'content' => array_map(fn ($t) => ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $t]]]]], ['Shaft 2 availability rose to 91 percent', 'Ore grade held at 3.4 grams per tonne', 'Haul cycle time fell by 7 minutes'])]]]],
            ]],
            ['title' => 'Output by week', 'layout' => 'data', 'els' => [
                $this->text('h', 'Output by week against plan', [140, 90, 1600, 120], ['font' => '@heading', 'size' => 64, 'weight' => 700]),
                ['id' => 'c1', 'type' => 'chart', 'x' => 140, 'y' => 240, 'w' => 1100, 'h' => 700, 'chart' => ['type' => 'combo', 'data' => ['categories' => ['W1', 'W2', 'W3', 'W4', 'W5', 'W6', 'W7', 'W8'], 'series' => [['name' => 'Actual (kt)', 'values' => [118, 124, 121, 133, 129, 138, 141, 136], 'kind' => 'bar'], ['name' => 'Plan (kt)', 'values' => [120, 122, 124, 126, 128, 130, 132, 134], 'kind' => 'line']]], 'opt' => ['legend' => 'bottom', 'labels' => 'none', 'grid' => true], 'src' => ['kind' => 'manual']]],
                $this->text('n', "Actual output passed plan in six of eight weeks.\nSource: shift reports, weeks 27 to 34.", [1320, 300, 500, 400], ['size' => 36, 'color' => '@muted']),
            ]],
            ['title' => 'Safety and cost at a glance', 'layout' => 'kpi', 'els' => [
                $this->text('h', 'Safety and cost at a glance', [140, 90, 1600, 120], ['font' => '@heading', 'size' => 64, 'weight' => 700]),
                ['id' => 'k', 'type' => 'chart', 'x' => 140, 'y' => 300, 'w' => 1640, 'h' => 300, 'chart' => ['type' => 'kpi', 'data' => ['kpis' => [['label' => 'LTIFR', 'value' => '0.8', 'delta' => -12, 'unit' => ''], ['label' => 'Cost per tonne', 'value' => 'R412', 'delta' => -3.1], ['label' => 'Availability', 'value' => '91%', 'delta' => 4.2]]], 'opt' => []]],
                ['id' => 'tb', 'type' => 'table', 'x' => 140, 'y' => 660, 'w' => 1640, 'h' => 300, 'header' => true, 'band' => true, 'size' => 30, 'lines' => 'rows', 'colw' => [0.4, 0.2, 0.2, 0.2], 'rowh' => [60, 60, 60, 60],
                    'rows' => [[['t' => 'Metric', 'b' => true], ['t' => 'Q2', 'b' => true, 'al' => 'r'], ['t' => 'Q3', 'b' => true, 'al' => 'r'], ['t' => 'Change', 'b' => true, 'al' => 'r']], [['t' => 'Tonnes milled (kt)'], ['t' => '1,012', 'al' => 'r'], ['t' => '1,053', 'al' => 'r'], ['t' => '+4.1%', 'al' => 'r']], [['t' => 'Grade (g/t)'], ['t' => '3.4', 'al' => 'r'], ['t' => '3.4', 'al' => 'r'], ['t' => '0.0%', 'al' => 'r']], [['t' => 'Unit cost (R/t)'], ['t' => '425', 'al' => 'r'], ['t' => '412', 'al' => 'r'], ['t' => '-3.1%', 'al' => 'r']]]],
            ]],
            ['title' => 'Next steps', 'layout' => 'closing', 'bg' => '@surface', 'els' => [
                $this->text('h', 'Decisions we need this month', [140, 200, 1600, 140], ['font' => '@heading', 'size' => 84, 'weight' => 700]),
                $this->text('b', "Approve the shaft 3 ventilation upgrade\nConfirm the October maintenance window\nSign off the revised plan for Q4", [140, 420, 1500, 400], ['size' => 48, 'lh' => 1.6]),
                ['id' => 'i', 'type' => 'icon', 'name' => 'target', 'x' => 1560, 'y' => 120, 'w' => 220, 'h' => 220, 'color' => '@accent', 'sw' => 2],
            ]],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function pitchSlides(): array
    {
        return [
            ['title' => 'Untitled', 'layout' => 'title', 'els' => [$this->text('t', 'Click to add a title', [140, 380, 1500, 200], ['font' => '@heading', 'size' => 96, 'weight' => 700])]],
            ['title' => 'The problem', 'layout' => 'bullets', 'els' => [
                $this->text('h', 'The problem', [140, 100, 1600, 140], ['font' => '@heading', 'size' => 76, 'weight' => 700]),
                $this->text('b', 'Small mines lose weeks to paper reporting. We are the best and leading solution, always.', [140, 320, 1400, 400], ['size' => 48]),
            ]],
        ];
    }
}
