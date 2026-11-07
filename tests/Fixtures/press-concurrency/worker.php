<?php

use App\Models\Deck;
use App\Models\Slide;
use App\Models\User;
use App\Services\Press\OpApplier;
use Illuminate\Contracts\Console\Kernel;

/**
 * One writer process for ConcurrentOpsTest. Boots the app against the SQLite file named in DB_DATABASE and applies
 * batches of operations to one deck through the same OpApplier the HTTP route uses.
 *
 *   php worker.php <deckId> <userId> <workerNo> <batches> [repeatKeys]
 *
 * Prints one JSON line: {"worker":n,"ok":k,"errors":[...],"replays":r}
 */
require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $deckId, $userId, $worker, $batches] = $argv;
$repeat = ($argv[5] ?? '') === 'repeat';
$endless = ($argv[5] ?? '') === 'endless'; // never finishes by itself: the test kills it in the middle of a batch

$deck = Deck::withoutGlobalScopes()->findOrFail((int) $deckId);
$user = User::findOrFail((int) $userId);
$slideId = (int) Slide::withoutGlobalScopes()->where('deck_id', $deck->id)->orderBy('sort_order')->value('id');
$applier = app(OpApplier::class);

$ok = 0;
$replays = 0;
$errors = [];

for ($b = 0; $b < (int) $batches || $endless; $b++) {
    $ops = [
        ['op' => 'el.add', 'slide' => $slideId, 'el' => ['id' => "w{$worker}b{$b}a", 'type' => 'shape', 'kind' => 'rect', 'x' => $b, 'y' => (int) $worker, 'w' => 10, 'h' => 10]],
        ['op' => 'el.add', 'slide' => $slideId, 'el' => ['id' => "w{$worker}b{$b}b", 'type' => 'shape', 'kind' => 'ellipse', 'x' => $b, 'y' => (int) $worker, 'w' => 10, 'h' => 10]],
        // every writer moves the shared element: the last one to commit wins, none may corrupt it
        ['op' => 'el.set', 'slide' => $slideId, 'id' => 'shared', 'p' => ['x' => ((int) $worker * 1000) + $b, 'rot' => $b]],
        ['op' => 'slide.add', 'tid' => "t{$worker}x{$b}", 'slide' => ['title' => "w{$worker} b{$b}"]],
    ];
    if ($endless) {
        // a fat batch (40 slides) so a kill is likely to land inside the transaction
        for ($n = 0; $n < 40; $n++) {
            $ops[] = ['op' => 'slide.add', 'tid' => "f{$worker}x{$b}x{$n}", 'slide' => ['title' => "fat {$n}", 'els' => [['id' => 'e'.$n, 'type' => 'shape', 'kind' => 'rect', 'x' => 1, 'y' => 1, 'w' => 5, 'h' => 5]]]];
        }
    }
    $key = "k-{$worker}-{$b}";
    $times = $repeat ? 2 : 1;
    for ($i = 0; $i < $times; $i++) {
        try {
            $r = $applier->apply($deck->fresh(), $user, "client-{$worker}", $key, $ops);
            $i === 0 ? $ok++ : $replays++;
        } catch (Throwable $e) {
            $errors[] = get_class($e).': '.substr($e->getMessage(), 0, 160);
        }
    }
}

echo json_encode(['worker' => (int) $worker, 'ok' => $ok, 'replays' => $replays, 'errors' => $errors]), "\n";
