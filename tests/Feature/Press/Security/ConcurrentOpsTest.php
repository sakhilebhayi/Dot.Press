<?php

namespace Tests\Feature\Press\Security;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Real concurrency, not a simulation: several PHP processes write to one SQLite file at the same time through
 * OpApplier, the way PHP workers on the shared host do. No batch may be lost, no document corrupted, no key applied
 * twice, and the change log must be gapless.
 */
#[Group('concurrency')]
class ConcurrentOpsTest extends TestCase
{
    private string $db;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 4);
        $this->db = sys_get_temp_dir().'/press-conc-'.bin2hex(random_bytes(4)).'.sqlite';
        touch($this->db);
    }

    protected function tearDown(): void
    {
        foreach ([$this->db, $this->db.'-wal', $this->db.'-shm'] as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    /** @return array<string, string> */
    private function env(): array
    {
        return [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->db, 'DB_URL' => '',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
            'BROADCAST_CONNECTION' => 'null', 'BCRYPT_ROUNDS' => '4', 'DB_BUSY_TIMEOUT' => '20000',
        ];
    }

    private function exec(array $cmd, ?string $input = null): string
    {
        $p = new Process($cmd, $this->root, $this->env() + ['PATH' => (string) getenv('PATH'), 'HOME' => (string) getenv('HOME')], $input, 120);
        $p->mustRun();

        return $p->getOutput();
    }

    private function seed(): array
    {
        $this->exec([PHP_BINARY, 'artisan', 'migrate', '--force', '--no-interaction']);
        $out = $this->exec([PHP_BINARY, 'artisan', 'tinker', '--execute', <<<'PHP'
$u = App\Models\User::factory()->withPersonalTeam()->create();
$p = App\Models\Project::create(['user_id' => $u->id, 'team_id' => $u->currentTeam->id, 'name' => 'P', 'slug' => 'p']);
$d = App\Models\Deck::create(['project_id' => $p->id, 'user_id' => $u->id, 'title' => 'Race', 'sort_order' => 0, 'theme' => null, 'sections' => []]);
App\Models\Slide::create(['deck_id' => $d->id, 'title' => 'One', 'sort_order' => 0, 'revision' => 0, 'els' => [['id' => 'shared', 'type' => 'shape', 'kind' => 'rect', 'x' => 0, 'y' => 0, 'w' => 50, 'h' => 50]]]);
echo "IDS:{$u->id}:{$d->id}";
PHP]);
        $this->assertMatchesRegularExpression('/IDS:(\d+):(\d+)/', $out, $out);
        preg_match('/IDS:(\d+):(\d+)/', $out, $m);

        return [(int) $m[1], (int) $m[2]];
    }

    /** @return list<array<string, mixed>> */
    private function fire(int $deckId, int $userId, int $workers, int $batches, bool $repeat): array
    {
        $procs = [];
        for ($w = 1; $w <= $workers; $w++) {
            $cmd = [PHP_BINARY, $this->root.'/tests/Fixtures/press-concurrency/worker.php', (string) $deckId, (string) $userId, (string) $w, (string) $batches];
            if ($repeat) {
                $cmd[] = 'repeat';
            }
            $p = new Process($cmd, $this->root, $this->env() + ['PATH' => (string) getenv('PATH'), 'HOME' => (string) getenv('HOME')], null, 180);
            $p->start();
            $procs[] = $p;
        }
        $out = [];
        foreach ($procs as $p) {
            $p->wait();
            $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput().$p->getOutput());
            $out[] = json_decode(trim($p->getOutput()), true);
        }

        return $out;
    }

    public function test_parallel_writers_lose_nothing_and_corrupt_nothing(): void
    {
        [$userId, $deckId] = $this->seed();
        $workers = 6;
        $batches = 8;

        $results = $this->fire($deckId, $userId, $workers, $batches, repeat: false);

        foreach ($results as $r) {
            $this->assertSame([], $r['errors'], 'worker '.$r['worker'].' hit an error');
            $this->assertSame($batches, $r['ok']);
        }

        $pdo = new \PDO('sqlite:'.$this->db);
        $accepted = $workers * $batches;

        // Every batch got its own log entry and the log has no gaps.
        $ids = $pdo->query('select id from deck_changes where deck_id = '.$deckId.' order by id')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertCount($accepted, $ids);
        $this->assertSame(range($ids[0], $ids[0] + $accepted - 1), array_map('intval', $ids));
        $this->assertSame($accepted, (int) $pdo->query('select rev from decks where id = '.$deckId)->fetchColumn());

        // Every element a worker added is on the first slide exactly once, and the shared element is whole.
        $slide = $pdo->query('select id, els, revision from slides where deck_id = '.$deckId.' order by sort_order, id limit 1')->fetch(\PDO::FETCH_ASSOC);
        $els = json_decode($slide['els'], true);
        $this->assertIsArray($els, 'the slide document must still be valid JSON');
        $found = array_count_values(array_column($els, 'id'));
        for ($w = 1; $w <= $workers; $w++) {
            for ($b = 0; $b < $batches; $b++) {
                $this->assertSame(1, $found["w{$w}b{$b}a"] ?? 0, "lost or doubled element w{$w}b{$b}a");
                $this->assertSame(1, $found["w{$w}b{$b}b"] ?? 0, "lost or doubled element w{$w}b{$b}b");
            }
        }
        $this->assertSame(1, $found['shared']);
        $shared = array_values(array_filter($els, fn ($e) => $e['id'] === 'shared'))[0];
        $this->assertSame('shape', $shared['type']);
        $this->assertSame(50, $shared['w']);
        $this->assertContains($shared['x'] % 1000, range(0, $batches - 1), 'x holds a value one writer really sent');
        $this->assertSame($accepted + 0, (int) $slide['revision'], 'one revision per accepted batch');

        // Every slide.add made a slide, and the order column is a permutation.
        $count = (int) $pdo->query('select count(*) from slides where deck_id = '.$deckId)->fetchColumn();
        $this->assertSame(1 + $accepted, $count);
        $orders = $pdo->query('select sort_order from slides where deck_id = '.$deckId.' order by sort_order')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(range(0, $count - 1), array_map('intval', $orders));
        $this->assertSame('ok', $pdo->query('pragma integrity_check')->fetchColumn());
    }

    public function test_a_key_sent_twice_at_once_is_applied_once(): void
    {
        [$userId, $deckId] = $this->seed();

        // Each worker sends each of its batches twice back to back with the same key.
        $results = $this->fire($deckId, $userId, 4, 5, repeat: true);

        foreach ($results as $r) {
            $this->assertSame([], $r['errors']);
            $this->assertSame(5, $r['ok']);
            $this->assertSame(5, $r['replays']);
        }
        $pdo = new \PDO('sqlite:'.$this->db);
        $this->assertSame(20, (int) $pdo->query('select count(*) from deck_changes where deck_id = '.$deckId)->fetchColumn());
        $els = json_decode($pdo->query('select els from slides where deck_id = '.$deckId.' order by sort_order, id limit 1')->fetchColumn(), true);
        $this->assertCount(1 + 4 * 5 * 2, $els);
    }

    public function test_killing_writers_mid_batch_leaves_a_consistent_deck(): void
    {
        [$userId, $deckId] = $this->seed();

        $procs = [];
        for ($w = 1; $w <= 4; $w++) {
            $p = new Process([PHP_BINARY, $this->root.'/tests/Fixtures/press-concurrency/worker.php', (string) $deckId, (string) $userId, (string) $w, '0', 'endless'], $this->root, $this->env() + ['PATH' => (string) getenv('PATH'), 'HOME' => (string) getenv('HOME')], null, 60);
            $p->start();
            $procs[] = $p;
        }
        usleep(1_800_000);
        foreach ($procs as $p) {
            $p->signal(SIGKILL);
        }
        foreach ($procs as $p) {
            $p->wait();
        }

        $pdo = new \PDO('sqlite:'.$this->db);
        $this->assertSame('ok', $pdo->query('pragma integrity_check')->fetchColumn());

        // The deck revision, the log and the slides agree: a killed batch left no half of itself behind.
        $rev = (int) $pdo->query('select rev from decks where id = '.$deckId)->fetchColumn();
        $changes = $pdo->query('select ops from deck_changes where deck_id = '.$deckId.' order by id')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(count($changes), $rev);
        $this->assertGreaterThan(0, $rev, 'at least one batch should have committed before the kill');

        $added = 0;
        foreach ($changes as $json) {
            $ops = json_decode($json, true);
            $this->assertIsArray($ops);
            foreach ($ops as $op) {
                $added += $op['op'] === 'slide.add' ? 1 : 0;
            }
        }
        $slides = (int) $pdo->query('select count(*) from slides where deck_id = '.$deckId)->fetchColumn();
        $this->assertSame(1 + $added, $slides, 'slides on disk must equal the slides the committed log created');

        $els = json_decode($pdo->query('select els from slides where deck_id = '.$deckId.' order by sort_order, id limit 1')->fetchColumn(), true);
        $this->assertIsArray($els);
        $ids = array_column($els, 'id');
        $this->assertSame($ids, array_values(array_unique($ids)), 'no element id twice');
    }
}
