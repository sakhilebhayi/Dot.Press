<?php

namespace Tests\Support;

use App\Models\Asset;
use App\Models\Deck;
use App\Models\Project;
use App\Models\Slide;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/** Creates real Deck, Slide and Asset rows from the sample document. */
trait BuildsPressDecks
{
    protected function pressUser(): User
    {
        return User::factory()->withPersonalTeam()->create();
    }

    protected function pressProject(User $user): Project
    {
        return Project::create(['user_id' => $user->id, 'team_id' => $user->currentTeam->id, 'name' => 'Press project', 'slug' => 'press-project-'.$user->id]);
    }

    /** Stores the sample pictures as assets 1 and 2 of the project (ids are remapped inside the document). @return array<int, int> sample id => asset id */
    protected function pressAssets(Project $project, User $user): array
    {
        $map = [];
        $resolver = PressSampleDeck::assets();
        foreach ([1, 2] as $sampleId) {
            $a = $resolver->find($sampleId);
            $path = 'projects/'.$project->id.'/assets/sample-'.$sampleId.'.'.($a['mime'] === 'image/png' ? 'png' : 'jpg');
            Storage::disk('local')->put($path, $a['bytes']);
            $asset = Asset::create([
                'project_id' => $project->id, 'uploaded_by' => $user->id, 'disk' => 'local', 'path' => $path, 'original_name' => $a['name'],
                'mime_type' => $a['mime'], 'size' => strlen($a['bytes']), 'width' => $a['width'], 'height' => $a['height'], 'kind' => 'image',
            ]);
            $map[$sampleId] = $asset->id;
        }

        return $map;
    }

    /** @param array<string, mixed>|null $doc */
    protected function pressDeck(User $user, ?array $doc = null, bool $withAssets = true): Deck
    {
        $doc ??= PressSampleDeck::doc();
        $project = $this->pressProject($user);
        $assets = $withAssets ? $this->pressAssets($project, $user) : [];
        $deck = Deck::create([
            'project_id' => $project->id, 'user_id' => $user->id, 'title' => $doc['deck']['title'] ?? 'Sample deck', 'theme' => $doc['deck']['theme'] ?? null,
            'sort_order' => 0, 'w' => $doc['deck']['w'], 'h' => $doc['deck']['h'], 'sections' => $doc['deck']['sections'] ?? [],
        ]);
        foreach ($doc['slides'] as $i => $s) {
            $els = json_decode(str_replace(['"asset":1', '"asset":2'], ['"asset":'.($assets[1] ?? 0), '"asset":'.($assets[2] ?? 0)], (string) json_encode($s['els'])), true);
            $deck->slides()->create([
                'title' => $s['title'], 'notes' => $s['notes'], 'layout' => $s['layout'], 'sort_order' => $i, 'els' => $els, 'bg' => $s['bg'],
                'transition' => $s['transition'], 'section_id' => $s['section_id'], 'hidden' => $s['hidden'],
            ]);
        }

        return $deck;
    }

    /** A deck of plain text slides, for size tests. */
    protected function plainDoc(int $slides): array
    {
        $out = [];
        for ($i = 1; $i <= $slides; $i++) {
            $out[] = ['id' => 's'.$i, 'title' => 'Slide '.$i, 'notes' => '', 'section_id' => null, 'hidden' => false, 'layout' => 'blank', 'bg' => null, 'transition' => null, 'rev' => 0, 'els' => [
                ['id' => 't', 'type' => 'text', 'x' => 100, 'y' => 100, 'w' => 800, 'h' => 200, 'size' => 48, 'doc' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Slide '.$i]]]]]],
            ]];
        }

        return ['deck' => ['id' => 1, 'title' => 'Plain', 'w' => 1920, 'h' => 1080, 'theme' => null, 'sections' => []], 'slides' => $out];
    }
}
