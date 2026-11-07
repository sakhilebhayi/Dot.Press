<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deck;
use App\Models\DeckActivity;
use App\Services\Press\ActivitySummary;
use Illuminate\Http\Request;

class DeckActivityController extends Controller
{
    public function index(Request $request, Deck $deck): array
    {
        $this->authorize('view', $deck);

        $data = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $page = DeckActivity::where('deck_id', $deck->id)->with('user:id,name')
            ->orderByDesc('id')->paginate($data['per_page'] ?? 30);

        return [
            'data' => $page->getCollection()->map(fn (DeckActivity $row) => [
                'id' => $row->id,
                'event' => $row->event,
                'summary' => ActivitySummary::for($row, $row->user?->name),
                'user' => $row->user ? ['id' => $row->user->id, 'name' => $row->user->name] : null,
                'meta' => $row->meta,
                'created_at' => optional($row->created_at)->toIso8601String(),
            ])->values()->all(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
        ];
    }
}
