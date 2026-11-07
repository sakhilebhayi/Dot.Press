<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\Deck;
use App\Models\DeckChange;
use App\Models\Project;
use App\Models\Slide;
use App\Models\User;
use App\Services\Press\Assets\AssetRejected;
use App\Services\Press\Assets\AssetStore;
use App\Services\Press\Bench\DeckGenerator;
use App\Services\Press\DeckDocument;
use App\Services\Press\DeckHash;
use App\Services\Press\Export\ExportService;
use App\Services\Press\Export\Pdf\PdfExporter;
use App\Services\Press\Export\Pptx\PptxExporter;
use App\Services\Press\Import\ImportedDeckBuilder;
use App\Services\Press\Import\PptxImporter;
use App\Services\Press\OpApplier;
use App\Services\Press\PresentPackage;
use App\Services\Press\ThemeLibrary;
use App\Services\Press\VersionService;
use Illuminate\Cache\RateLimiter;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Dot.Press performance benchmark (R-40). A development tool: it refuses to run in production and works in its own
 * throw-away SQLite file (storage/app/bench/bench.sqlite) unless --use-current-db is given.
 *
 *   php artisan press:bench                              every section, decks of 10, 100 and 300 slides
 *   php artisan press:bench --only=doc,ops --sizes=100   chosen sections
 *   php artisan press:bench --json=before.json           keep the numbers
 *   php artisan press:bench --compare=before.json        print a previous run beside this one
 *   php artisan press:bench --seed-only --use-current-db --sizes=300   create big decks for the browser benchmark
 *
 * Requests are dispatched through the real HTTP kernel in this process, so routing, middleware, policies, global
 * scopes, validation and JSON encoding are all measured; only the web server and PHP start-up are not. Concurrency,
 * memory-limited work and exporters run in child PHP processes (the `--worker` option), as PHP-FPM workers would.
 * Method and numbers: docs/programme/R40_PERFORMANCE.md.
 */
class PressBench extends Command
{
    private const SECTIONS = ['doc', 'ops', 'changes', 'versions', 'library', 'hash', 'presence', 'locks', 'export', 'import', 'present', 'asset', 'prune'];

    protected $signature = 'press:bench
        {--only= : Comma separated sections (doc, ops, changes, versions, library, hash, presence, locks, export, import, present, asset, prune)}
        {--sizes=10,100,300 : Deck sizes in slides}
        {--runs=5 : Repetitions per timing (median is reported)}
        {--json= : Write the results to this JSON file}
        {--compare= : Print a previous JSON result beside this run}
        {--use-current-db : Use the configured database instead of the throw-away benchmark file}
        {--seed-only : Only create the benchmark decks (for the browser benchmark) and print their ids}
        {--tokens=0 : With --seed-only: also create this many editor users with bearer tokens, written to storage/app/bench/tokens.txt}
        {--worker= : Internal: run as a child worker (ops, presence, export, import, asset)}
        {--args= : Internal: JSON arguments for the worker}';

    protected $description = 'Benchmark Dot.Press with large decks (development only, refuses to run in production).';

    /** @var array<string, array<string, mixed>> */
    private array $results = [];

    /** @var list<array{sql: string, ms: float}> */
    private array $queries = [];

    private bool $listening = false;

    private ?User $user = null;

    private ?Project $project = null;

    /** @var list<int> */
    private array $assetIds = [];

    /** @var array<int, Deck> */
    private array $decks = [];

    private string $dbPath = '';

    public function handle(): int
    {
        if ($this->laravel->environment('production')) {
            $this->error('press:bench refuses to run in production.');

            return self::FAILURE;
        }

        if ($this->option('worker')) {
            return $this->runWorker((string) $this->option('worker'), (array) json_decode((string) $this->option('args'), true));
        }

        $this->prepareEnvironment($this->option('use-current-db') ? null : storage_path('app/bench/bench.sqlite'), fresh: ! $this->option('use-current-db'));
        $this->seedBase();

        $sizes = array_values(array_filter(array_map('intval', explode(',', (string) $this->option('sizes')))));
        if ($this->option('seed-only')) {
            foreach ($sizes as $size) {
                $deck = $this->deck($size);
                $this->line("deck {$deck->id}: {$size} slides, owner {$this->user->email}");
                // The same document as a file, for the node benchmarks (scripts/bench/engine.mjs).
                $file = storage_path("app/bench/doc-{$size}.json");
                file_put_contents($file, json_encode(DeckDocument::toArray($deck), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                $this->line("  document written to {$file}");
            }

            // A bearer token for scripts/bench/http.mjs (a test token for this throw-away benchmark user).
            $this->line('token '.$this->user->createToken('bench-'.uniqid())->plainTextToken);
            $count = (int) $this->option('tokens');
            if ($count > 0) {
                $lines = [];
                for ($i = 0; $i < $count; $i++) {
                    $editor = User::query()->firstOrCreate(['email' => "bench-ed-{$i}@press.test"], ['name' => "Bench Editor {$i}", 'password' => bcrypt('password'), 'email_verified_at' => now()]);
                    if (! $this->user->currentTeam->hasUser($editor)) {
                        $this->user->currentTeam->users()->attach($editor, ['role' => 'editor']);
                    }
                    $editor->forceFill(['current_team_id' => $this->user->currentTeam->id])->save();
                    $lines[] = $editor->createToken('bench-'.uniqid())->plainTextToken;
                }
                file_put_contents(storage_path('app/bench/tokens.txt'), implode("\n", $lines)."\n");
                $this->line("{$count} editor tokens written to storage/app/bench/tokens.txt");
            }

            return self::SUCCESS;
        }

        $only = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('only')))));
        $sections = $only === [] ? self::SECTIONS : $only;
        foreach ($sections as $section) {
            if (! in_array($section, self::SECTIONS, true)) {
                $this->error("Unknown section {$section}. Choose from: ".implode(', ', self::SECTIONS));

                return self::FAILURE;
            }
        }

        $this->meta();
        foreach ($sections as $section) {
            $this->info("== {$section}");
            $started = microtime(true);
            try {
                $this->{'section'.ucfirst($section)}($sizes, max(1, (int) $this->option('runs')));
            } catch (Throwable $e) {
                $this->error("{$section} failed: ".$e->getMessage().' at '.basename($e->getFile()).':'.$e->getLine());
                $this->put($section, 'error', $e->getMessage());
            }
            $this->line(sprintf('   (%.1f s)', microtime(true) - $started));
        }

        $this->report();

        return self::SUCCESS;
    }

    // ============================================================================================================ environment

    private function prepareEnvironment(?string $dbPath, bool $fresh): void
    {
        if ($dbPath !== null) {
            @mkdir(dirname($dbPath), 0775, true);
            if ($fresh) {
                foreach ([$dbPath, $dbPath.'-wal', $dbPath.'-shm'] as $f) {
                    @unlink($f);
                }
                touch($dbPath);
            }
            config(['database.connections.sqlite.database' => $dbPath, 'database.default' => 'sqlite']);
            DB::purge('sqlite');
            $this->dbPath = $dbPath;
            config(['filesystems.disks.local.root' => storage_path('app/bench/files')]);
            Storage::forgetDisk('local');
            if ($fresh) {
                Artisan::call('migrate', ['--force' => true]);
            }
        }

        // The throttle counters live in the cache; an in-memory store that we flush between calls keeps the benchmark
        // from measuring its own rate limits (the limits are reviewed in the report instead).
        config(['cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync']);
        Cache::setDefaultDriver('array');
        // The rate limiter was built at boot with the configured store; point it at the in-memory one too.
        $limiter = app(RateLimiter::class);
        (new \ReflectionProperty($limiter, 'cache'))->setValue($limiter, Cache::store('array'));

        if (! $this->listening) {
            DB::listen(function ($query): void {
                $this->queries[] = ['sql' => $query->sql, 'ms' => (float) $query->time];
            });
            $this->listening = true;
        }
    }

    private function seedBase(): void
    {
        $existing = User::query()->where('email', 'bench@press.test')->first();
        if ($existing !== null) {
            $this->user = $existing;
            $this->project = Project::withoutGlobalScopes()->where('team_id', $existing->currentTeam->id)->first();
            $this->assetIds = Asset::withoutGlobalScopes()->where('project_id', $this->project->id)->where('path', 'like', '%bench-%')->orderBy('id')->limit(4)->pluck('id')->all();
            if ($this->assetIds === []) {
                $this->assetIds = DeckGenerator::createAssets($this->project, $this->user);
            }
            Auth::guard('web')->setUser($this->user);

            return;
        }

        $this->user = User::factory()->withPersonalTeam()->create(['name' => 'Bench Owner', 'email' => 'bench@press.test', 'password' => bcrypt('password')]);
        $this->project = Project::create(['user_id' => $this->user->id, 'team_id' => $this->user->currentTeam->id, 'name' => 'Dot.Press Workspace', 'slug' => 'dot-press-workspace']);
        $this->assetIds = DeckGenerator::createAssets($this->project, $this->user);
        Auth::guard('web')->setUser($this->user);
    }

    private function deck(int $slides): Deck
    {
        if (! isset($this->decks[$slides])) {
            $title = "Bench {$slides}";
            $existing = Deck::withoutGlobalScopes()->where('title', $title)->where('user_id', $this->user->id)->first();
            $this->decks[$slides] = $existing ?? (new DeckGenerator($this->assetIds))->createDeck($this->project, $this->user, $title, $slides);
        }

        return $this->decks[$slides];
    }

    /** Throw-away copy of the generated deck so a section that writes does not disturb the others. */
    private function freshDeck(int $slides, string $label): Deck
    {
        return (new DeckGenerator($this->assetIds))->createDeck($this->project, $this->user, "Bench {$label} {$slides} ".uniqid(), $slides);
    }

    private function meta(): void
    {
        $this->put('env', 'php', PHP_VERSION);
        $this->put('env', 'memory_limit', ini_get('memory_limit'));
        $this->put('env', 'opcache_cli', (string) ini_get('opcache.enable_cli'));
        $this->put('env', 'sqlite', DB::selectOne('select sqlite_version() as v')->v ?? '');
        $this->put('env', 'journal_mode', DB::selectOne('pragma journal_mode')->journal_mode ?? '');
        $this->put('env', 'xdebug', extension_loaded('xdebug') ? 'on' : 'off');
        $this->put('env', 'date', now()->toDateTimeString());
        $base = $this->child('baseline', [], '128M');
        $this->put('env', 'bare_request_peak_mb', $base['peak_mb'] ?? null);
    }

    // ============================================================================================================ measuring

    /**
     * One request through the HTTP kernel.
     *
     * @param  array<string, mixed>|null  $json
     * @return array{status: int, ms: float, bytes: int, gz: int, queries: int, sql_ms: float, peak_mb: float, nplus1: list<string>, body: string, request_bytes: int}
     */
    private function hit(string $method, string $uri, ?array $json = null, bool $inertia = false, ?User $as = null, bool $gzip = false): array
    {
        Auth::guard('web')->setUser($as ?? $this->user);
        Cache::flush();
        $server = ['HTTP_ACCEPT' => $inertia ? 'text/html' : 'application/json', 'HTTP_HOST' => 'localhost'];
        if ($gzip) {
            $server['HTTP_ACCEPT_ENCODING'] = 'gzip';
        }
        $content = null;
        if ($json !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
            $content = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $request = Request::create($uri, $method, [], [], [], $server, $content);
        $request->setUserResolver(fn () => $as ?? $this->user);

        $this->queries = [];
        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $t = hrtime(true);
        $kernel = app(HttpKernel::class);
        $response = $kernel->handle($request);
        $streamed = $response instanceof StreamedResponse;
        $streamedBytes = 0;
        if ($streamed) {
            // A streamed answer is produced while it is sent: count its bytes as they leave, without keeping them.
            ob_start(function (string $chunk) use (&$streamedBytes): string {
                $streamedBytes += strlen($chunk);

                return '';
            }, 65536);
            $response->sendContent();
            ob_end_clean();
        }
        $ms = (hrtime(true) - $t) / 1e6;
        $peak = (memory_get_peak_usage() - $before) / 1048576;
        $body = $streamed ? '' : (string) $response->getContent();
        $queries = $this->queries;
        $kernel->terminate($request, $response);

        $patterns = [];
        foreach ($queries as $q) {
            $key = preg_replace(['/\'[^\']*\'/', '/\b\d+\b/', '/\(\s*\?(?:\s*,\s*\?)+\s*\)/', '/\s+/'], ["'?'", '?', '(?)', ' '], $q['sql']);
            $patterns[$key] = ($patterns[$key] ?? 0) + 1;
        }
        arsort($patterns);
        $nplus1 = [];
        foreach ($patterns as $sql => $count) {
            if ($count >= 5) {
                $nplus1[] = $count.' x '.mb_substr($sql, 0, 140);
            }
        }

        return [
            'status' => $response->getStatusCode(), 'ms' => $ms, 'bytes' => $streamed ? $streamedBytes : strlen($body), 'gz' => $streamed ? ($response->headers->get('Content-Encoding') === 'gzip' ? $streamedBytes : 0) : ($body === '' ? 0 : strlen((string) gzencode($body, 6))),
            'queries' => count($queries), 'sql_ms' => array_sum(array_column($queries, 'ms')), 'peak_mb' => $peak, 'nplus1' => $nplus1, 'body' => $body,
            'request_bytes' => $content === null ? 0 : strlen($content),
        ];
    }

    /**
     * Repeats a request; reports the median time, and the cost of the first (cold) call.
     *
     * @param  callable(): array<string, mixed>  $fn
     * @return array<string, mixed>
     */
    private function repeat(callable $fn, int $runs): array
    {
        $all = [];
        for ($i = 0; $i < $runs; $i++) {
            $all[] = $fn();
        }
        usort($all, fn ($a, $b) => $a['ms'] <=> $b['ms']);
        $median = $all[intdiv(count($all), 2)];
        $median['ms_min'] = $all[0]['ms'];
        $median['ms_max'] = $all[count($all) - 1]['ms'];

        return $median;
    }

    private function put(string $section, string $key, mixed $value): void
    {
        $this->results[$section][$key] = is_float($value) ? round($value, 2) : $value;
    }

    /** @param  array<string, mixed>  $r */
    private function putCall(string $section, string $prefix, array $r): void
    {
        $this->put($section, "{$prefix}.ms", $r['ms']);
        $this->put($section, "{$prefix}.queries", $r['queries']);
        $this->put($section, "{$prefix}.bytes", $r['bytes']);
        $this->put($section, "{$prefix}.peak_delta_mb", $r['peak_mb']);
        if ($r['gz'] > 0) {
            $this->put($section, "{$prefix}.gzip_bytes", $r['gz']);
        }
        if ($r['nplus1'] !== []) {
            $this->put($section, "{$prefix}.n_plus_1", implode(' | ', $r['nplus1']));
        }
        if (($r['status'] ?? 200) >= 400) {
            $this->put($section, "{$prefix}.status", $r['status']);
        }
    }

    // ============================================================================================================ sections

    /** GET document, the editor page and the slide list. */
    private function sectionDoc(array $sizes, int $runs): void
    {
        foreach ($sizes as $size) {
            $deck = $this->deck($size);
            $r = $this->repeat(fn () => $this->hit('GET', "/api/decks/{$deck->id}/document"), $runs);
            $this->putCall('doc', "{$size}.get_document", $r);
            $this->put('doc', "{$size}.get_document.ms_range", round($r['ms_min']).'-'.round($r['ms_max']));
            $g = $this->hit('GET', "/api/decks/{$deck->id}/document", null, false, null, true);
            $this->put('doc', "{$size}.get_document.gzip_stream_bytes", $g['bytes']);
            $this->put('doc', "{$size}.get_document.gzip_stream_ms", $g['ms']);

            $r = $this->repeat(fn () => $this->hit('GET', "/decks/{$deck->id}", null, true), max(1, min($runs, 3)));
            $this->putCall('doc', "{$size}.editor_page", $r);

            $r = $this->repeat(fn () => $this->hit('GET', "/api/decks/{$deck->id}"), $runs);
            $this->putCall('doc', "{$size}.deck_summary", $r);

            // The pages that hand the whole deck to the browser at once.
            foreach (['present' => 'present', 'presenter' => 'presenter', 'print' => 'print', 'audience' => 'audience'] as $name => $path) {
                $r = $this->repeat(fn () => $this->hit('GET', "/decks/{$deck->id}/{$path}", null, true), 1);
                $this->putCall('doc', "{$size}.page_{$name}", $r);
            }

            $t = hrtime(true);
            $json = json_encode(DeckDocument::toArray($deck), JSON_UNESCAPED_SLASHES);
            $this->put('doc', "{$size}.toArray_plus_encode.ms", (hrtime(true) - $t) / 1e6);
            $this->put('doc', "{$size}.json_mb", strlen($json) / 1048576);
        }
    }

    /** Operations batches: one op, a 50 element drag, a 2 MB batch of 200 ops. */
    private function sectionOps(array $sizes, int $runs): void
    {
        $gen = new DeckGenerator($this->assetIds);
        foreach ($sizes as $size) {
            $deck = $this->freshDeck($size, 'ops');
            $first = Slide::withoutGlobalScopes()->where('deck_id', $deck->id)->orderBy('sort_order')->first();
            $elementIds = array_column($first->els, 'id');
            $slideIds = Slide::withoutGlobalScopes()->where('deck_id', $deck->id)->orderBy('sort_order')->pluck('id')->all();
            $n = 0;
            $post = fn (array $ops) => $this->hit('POST', "/api/decks/{$deck->id}/ops", ['client' => 'bench', 'key' => 'bench-'.$size.'-'.(++$n).'-'.uniqid(), 'ops' => $ops]);

            // First batch on a deck with no version yet: includes the automatic version snapshot.
            $r = $post(DeckGenerator::dragOps($first->id, $elementIds, 1, 3));
            $this->putCall('ops', "{$size}.first_batch_with_auto_version", $r);

            $r = $this->repeat(fn () => $post(DeckGenerator::dragOps($first->id, $elementIds, 1, random_int(1, 90))), $runs);
            $this->putCall('ops', "{$size}.single_el_set", $r);

            $r = $this->repeat(fn () => $post(DeckGenerator::dragOps($first->id, $elementIds, 30, random_int(1, 90))), $runs);
            $this->putCall('ops', "{$size}.drag_30", $r);

            $r = $this->repeat(fn () => $post(DeckGenerator::dragOps($first->id, array_merge($elementIds, $elementIds), 50, random_int(1, 90))), $runs);
            $this->putCall('ops', "{$size}.drag_50_ops", $r);
            $this->put('ops', "{$size}.drag_50_ops.request_bytes", $r['request_bytes']);

            // 200 ops, about 2 MB: tables of ~10 KB each added across the deck.
            $ops = $gen->addOps(array_slice($slideIds, 0, max(1, min(count($slideIds), 20))), 200, 10_000, 'b'.$size);
            $r = $post($ops);
            $this->putCall('ops', "{$size}.bulk_200_ops_2mb", $r);
            $this->put('ops', "{$size}.bulk_200_ops_2mb.request_mb", $r['request_bytes'] / 1048576);

            // One slide add + move + delete cycle.
            $r = $post([['op' => 'slide.add', 'tid' => 'tmp1', 'after' => $slideIds[0], 'slide' => ['title' => 'New', 'els' => []]]]);
            $this->putCall('ops', "{$size}.slide_add", $r);
        }
    }

    /** The changes feed with a long log behind it. */
    private function sectionChanges(array $sizes, int $runs): void
    {
        $deck = $this->deck(in_array(100, $sizes, true) ? 100 : $sizes[0]);
        $other = $this->deck($sizes[0] === $deck->slides()->count() ? $sizes[count($sizes) - 1] : $sizes[0]);
        $rows = [];
        $op = json_encode([['op' => 'el.set', 'slide' => 1, 'id' => 's1e1', 'p' => ['x' => 100, 'y' => 120]], ['op' => 'el.set', 'slide' => 1, 'id' => 's1e2', 'p' => ['x' => 110, 'y' => 130]]]);
        $result = json_encode(['seq' => 1, 'deck_rev' => 1, 'slides' => [1 => 2], 'ids' => [], 'skipped' => []]);
        foreach ([$deck, $other] as $d) {
            for ($i = 0; $i < 5000; $i++) {
                $rows[] = ['deck_id' => $d->id, 'user_id' => $this->user->id, 'client' => 'bench', 'key' => 'seed-'.$d->id.'-'.$i, 'ops' => $op, 'result' => $result, 'created_at' => now()->subSeconds(5000 - $i)];
                if (count($rows) >= 500) {
                    DB::table('deck_changes')->insert($rows);
                    $rows = [];
                }
            }
        }
        $this->put('changes', 'log_rows_total', DeckChange::count());
        $head = (int) DeckChange::where('deck_id', $deck->id)->max('id');

        $r = $this->repeat(fn () => $this->hit('GET', "/api/decks/{$deck->id}/changes?after={$head}"), $runs * 4);
        $this->putCall('changes', 'idle_poll_nothing_new', $r);
        $r = $this->repeat(fn () => $this->hit('GET', '/api/decks/'.$deck->id.'/changes?after='.($head - 5)), $runs);
        $this->putCall('changes', 'poll_5_new', $r);
        $first = (int) DeckChange::where('deck_id', $deck->id)->min('id');
        $r = $this->repeat(fn () => $this->hit('GET', "/api/decks/{$deck->id}/changes?after={$first}"), $runs);
        $this->putCall('changes', 'catch_up_500_rows', $r);

        $t = hrtime(true);
        for ($i = 0; $i < 200; $i++) {
            DeckChange::query()->where('deck_id', $deck->id)->max('id');
        }
        $this->put('changes', 'max_id_lookup.ms', (hrtime(true) - $t) / 1e6 / 200);
        $plan = DB::select('explain query plan select max(id) from deck_changes where deck_id = ?', [$deck->id]);
        $this->put('changes', 'max_id_lookup.plan', implode('; ', array_map(fn ($p) => $p->detail, $plan)));
        $plan = DB::select('explain query plan select * from deck_changes where deck_id = ? and id > ? order by id limit 501', [$deck->id, $head]);
        $this->put('changes', 'feed_select.plan', implode('; ', array_map(fn ($p) => $p->detail, $plan)));
    }

    /** Version snapshots: cost, size, compression, frequency. */
    private function sectionVersions(array $sizes, int $runs): void
    {
        $versions = app(VersionService::class);
        foreach ($sizes as $size) {
            $deck = $this->freshDeck($size, 'ver');
            $peakBefore = memory_get_peak_usage();
            memory_reset_peak_usage();
            $t = hrtime(true);
            $v = $versions->create($deck, $this->user, 'manual', 'bench');
            $ms = (hrtime(true) - $t) / 1e6;
            $this->put('versions', "{$size}.snapshot.ms", $ms);
            $this->put('versions', "{$size}.snapshot.peak_mb", memory_get_peak_usage() / 1048576);
            $raw = DB::table('deck_versions')->where('id', $v->id)->selectRaw('length(snapshot) as n')->value('n');
            $this->put('versions', "{$size}.snapshot.stored_bytes", (int) $raw);
            $this->put('versions', "{$size}.snapshot.json_bytes", $v->size);
            $this->put('versions', "{$size}.snapshot.gzip_bytes", strlen((string) gzencode((string) $v->snapshot, 6)));

            $t = hrtime(true);
            $versions->autoIfDue($deck, $this->user);
            $this->put('versions', "{$size}.auto_if_due_skipped.ms", (hrtime(true) - $t) / 1e6);

            $r = $this->repeat(fn () => $this->hit('GET', "/api/decks/{$deck->id}/versions"), $runs);
            $this->putCall('versions', "{$size}.list", $r);
            $r = $this->repeat(fn () => $this->hit('GET', "/api/decks/{$deck->id}/versions/{$v->id}"), $runs);
            $this->putCall('versions', "{$size}.show", $r);

            $t = hrtime(true);
            $versions->restore($deck, $v->fresh(), $this->user);
            $this->put('versions', "{$size}.restore.ms", (hrtime(true) - $t) / 1e6);

            // An editing hour: auto version at most every 20 minutes -> 3 per hour per active deck, 40 kept.
            $this->put('versions', "{$size}.auto_per_active_hour", (int) (60 / VersionService::AUTO_INTERVAL_MINUTES));
            $this->put('versions', "{$size}.storage_40_autos_mb", 40 * (int) $raw / 1048576);
        }
    }

    /** The library with 200 decks. */
    private function sectionLibrary(array $sizes, int $runs): void
    {
        $gen = new DeckGenerator($this->assetIds);
        $have = Deck::withoutGlobalScopes()->where('title', 'like', 'Library deck %')->count();
        for ($i = $have; $i < 200; $i++) {
            $deck = Deck::create(['project_id' => $this->project->id, 'user_id' => $this->user->id, 'title' => 'Library deck '.$i, 'theme' => ThemeLibrary::find('slate'), 'w' => 1920, 'h' => 1080, 'sections' => []]);
            if ($i % 10 === 0) {
                DB::table('decks')->where('id', $deck->id)->update(['approved_hash' => str_repeat('a', 64), 'status' => 'draft']);
            }
            $rows = [];
            for ($s = 0; $s < 12; $s++) {
                $slide = $gen->slide($i * 100 + $s + 1);
                $rows[] = ['deck_id' => $deck->id, 'title' => $slide['title'], 'notes' => $slide['notes'], 'layout' => 'blank', 'sort_order' => $s, 'revision' => 0, 'hidden' => 0,
                    'els' => json_encode($slide['els']), 'created_at' => now(), 'updated_at' => now()];
            }
            DB::table('slides')->insert($rows);
        }
        $this->put('library', 'decks', Deck::withoutGlobalScopes()->count());
        $this->put('library', 'decks_with_approved_hash', Deck::withoutGlobalScopes()->whereNotNull('approved_hash')->count());

        $r = $this->repeat(fn () => $this->hit('GET', '/api/decks?per_page=24'), $runs);
        $this->putCall('library', 'list_24', $r);
        $body = json_decode($r['body'], true);
        $this->put('library', 'list_24.preview_bytes_per_deck', (int) round(strlen(json_encode(array_column($body['data'] ?? [], 'preview'))) / max(1, count($body['data'] ?? []))));
        $r = $this->repeat(fn () => $this->hit('GET', '/api/decks?per_page=60'), $runs);
        $this->putCall('library', 'list_60', $r);
        $cursor = $body['next_cursor'] ?? null;
        if ($cursor) {
            $r = $this->repeat(fn () => $this->hit('GET', '/api/decks?per_page=24&cursor='.urlencode($cursor)), $runs);
            $this->putCall('library', 'list_page_2', $r);
        }
        $r = $this->repeat(fn () => $this->hit('GET', '/api/decks?per_page=24&q=deck+1'), $runs);
        $this->putCall('library', 'search', $r);
        $r = $this->repeat(fn () => $this->hit('GET', '/api/decks?per_page=24&sort=title'), $runs);
        $this->putCall('library', 'sort_title', $r);
        $r = $this->repeat(fn () => $this->hit('GET', '/dashboard', null, true), max(1, min(3, $runs)));
        $this->putCall('library', 'page', $r);
    }

    private function sectionHash(array $sizes, int $runs): void
    {
        foreach ($sizes as $size) {
            $deck = $this->deck($size);
            $times = [];
            for ($i = 0; $i < $runs; $i++) {
                $t = hrtime(true);
                DeckHash::compute($deck);
                $times[] = (hrtime(true) - $t) / 1e6;
            }
            sort($times);
            $this->put('hash', "{$size}.compute.ms", $times[intdiv(count($times), 2)]);
        }
    }

    /** One presence beat in process, then 10 concurrent clients as child processes. */
    private function sectionPresence(array $sizes, int $runs): void
    {
        $deck = $this->deck(in_array(100, $sizes, true) ? 100 : $sizes[0]);
        $n = 0;
        $r = $this->repeat(fn () => $this->hit('POST', "/api/decks/{$deck->id}/presence", ['client' => 'tab-bench', 'slide' => Slide::withoutGlobalScopes()->where('deck_id', $deck->id)->value('id'), 'selection' => ['s1e1'], 'cursor' => ['x' => 10 + (++$n), 'y' => 20]]), $runs * 4);
        $this->putCall('presence', 'single_beat', $r);

        $result = $this->spawn('presence', 10, ['deck' => $deck->id, 'beats' => 30]);
        $this->summariseWorkers('presence', '10_clients', $result);
    }

    /** Four parallel writers on one SQLite file. */
    private function sectionLocks(array $sizes, int $runs): void
    {
        $size = in_array(100, $sizes, true) ? 100 : $sizes[0];
        $solo = $this->freshDeck($size, 'lock-solo');
        $one = $this->spawn('ops', 1, ['deck' => $solo->id, 'batches' => 30, 'ops' => 5]);
        $this->summariseWorkers('locks', '1_writer', $one);

        $deck = $this->freshDeck($size, 'lock-par');
        $four = $this->spawn('ops', 4, ['deck' => $deck->id, 'batches' => 30, 'ops' => 5]);
        $this->summariseWorkers('locks', '4_writers_same_deck', $four);

        $decks = [];
        for ($i = 0; $i < 4; $i++) {
            $decks[] = $this->freshDeck(max(10, intdiv($size, 4)), 'lock-sep')->id;
        }
        $sep = $this->spawn('ops', 4, ['decks' => $decks, 'batches' => 30, 'ops' => 5]);
        $this->summariseWorkers('locks', '4_writers_4_decks', $sep);
    }

    /** PDF and PPTX: time and peak memory, and the largest deck that survives a 128 MB memory limit. */
    private function sectionExport(array $sizes, int $runs): void
    {
        $slides = in_array(100, $sizes, true) ? 100 : max($sizes);
        $deck = $this->deck($slides);
        foreach (['pdf', 'pptx'] as $format) {
            $res = $this->child('export', ['deck' => $deck->id, 'format' => $format, 'slides' => $slides], '256M');
            $this->put('export', "{$format}.{$slides}_slides.ms", $res['ms'] ?? null);
            $this->put('export', "{$format}.{$slides}_slides.peak_mb", $res['peak_mb'] ?? null);
            $this->put('export', "{$format}.{$slides}_slides.mb", isset($res['bytes']) ? $res['bytes'] / 1048576 : null);
            if (isset($res['error'])) {
                $this->put('export', "{$format}.{$slides}_slides.error", $res['error']);
            }

            // Ceiling under the default 128 MB limit: grow the deck until the exporter fails or stops degrading.
            $ladder = [10, 25, 50, 100, 150, 200, 300, 450, 600];
            $deckMax = $this->deck(max($sizes));
            $survived = 0;
            $note = '';
            foreach ($ladder as $n) {
                if ($n > $deckMax->slides()->count() * 1 && $n > 300) {
                    // Larger than any generated deck: reuse slides cyclically in the worker.
                }
                $res = $this->child('export', ['deck' => $deckMax->id, 'format' => $format, 'slides' => $n], '128M');
                if (($res['ok'] ?? false) === true) {
                    $survived = $n;
                    $this->put('export', "{$format}.128M.{$n}_slides", round($res['ms'] / 1000, 1).' s, '.round($res['peak_mb']).' MB php peak, '.round($res['rss_mb'] ?? 0).' MB rss'.(isset($res['degraded']) ? ', '.$res['degraded'] : ''));
                } else {
                    $note = (string) ($res['error'] ?? 'failed');
                    $this->put('export', "{$format}.128M.{$n}_slides", 'FAILED: '.mb_substr($note, 0, 120));
                    break;
                }
            }
            $this->put('export', "{$format}.128M.max_slides_survived", $survived);
        }
    }

    private function sectionImport(array $sizes, int $runs): void
    {
        $slides = in_array(100, $sizes, true) ? 100 : max($sizes);
        $deck = $this->deck($slides);
        $file = storage_path('app/bench/import-'.$slides.'.pptx');
        $make = $this->child('export', ['deck' => $deck->id, 'format' => 'pptx', 'slides' => $slides, 'keep' => $file], '512M');
        $this->put('import', 'fixture.pptx_mb', isset($make['bytes']) ? $make['bytes'] / 1048576 : null);
        foreach (['256M', '128M'] as $limit) {
            $res = $this->child('import', ['file' => $file], $limit);
            $this->put('import', "{$slides}_slides.{$limit}", ($res['ok'] ?? false) ? round($res['ms'] / 1000, 2).' s, '.round($res['peak_mb']).' MB php peak, '.round($res['rss_mb'] ?? 0).' MB rss, '.$res['slides'].' slides' : 'FAILED: '.mb_substr((string) ($res['error'] ?? '?'), 0, 140));
        }
    }

    /** The offline present package for a deck with 40 images. */
    private function sectionPresent(array $sizes, int $runs): void
    {
        if (! is_file(base_path('public/build/standalone/press-player.iife.js'))) {
            $this->put('present', 'skipped', 'public/build/standalone is missing; run npm run build');

            return;
        }
        $ids = DeckGenerator::createAssets($this->project, $this->user, 40, 2400, 1350);
        $deck = (new DeckGenerator($ids))->createDeck($this->project, $this->user, 'Bench present 40 images', 20);
        $deck->slides()->each(function (Slide $slide, int $i) use ($ids): void {
            $els = array_values(array_filter($slide->els, fn ($e) => $e['type'] !== 'image'));
            $els[] = ['id' => 'p'.$i.'a', 'type' => 'image', 'x' => 100, 'y' => 100, 'w' => 800, 'h' => 450, 'src' => ['asset' => $ids[($i * 2) % 40]], 'fit' => 'cover', 'alt' => 'a'];
            $els[] = ['id' => 'p'.$i.'b', 'type' => 'image', 'x' => 1000, 'y' => 100, 'w' => 800, 'h' => 450, 'src' => ['asset' => $ids[($i * 2 + 1) % 40]], 'fit' => 'cover', 'alt' => 'b'];
            $slide->els = $els;
            $slide->save();
        });
        $this->put('present', 'source_images_mb', Asset::withoutGlobalScopes()->whereIn('id', $ids)->sum('size') / 1048576);
        foreach (['256M', '128M'] as $limit) {
            $res = $this->child('present', ['deck' => $deck->id], $limit);
            $this->put('present', "package_40_images.{$limit}", ($res['ok'] ?? false)
                ? round($res['ms']).' ms, '.round($res['peak_mb']).' MB php peak, '.round($res['rss_mb'] ?? 0).' MB rss, html '.round($res['bytes'] / 1048576, 1).' MB (gzip '.round($res['gz'] / 1048576, 1).' MB), warnings '.$res['warnings']
                : 'FAILED: '.mb_substr((string) ($res['error'] ?? '?'), 0, 140));
        }
    }

    /** The asset pipeline on a 12 megapixel photograph. */
    private function sectionAsset(array $sizes, int $runs): void
    {
        foreach ([[4000, 3000], [6000, 4500]] as [$w, $h]) {
            $mp = round($w * $h / 1e6);
            foreach (['256M', '128M'] as $limit) {
                // A different picture per run: the store de-duplicates identical files by hash.
                $file = storage_path("app/bench/photo-{$w}x{$h}-{$limit}.jpg");
                if (! is_file($file)) {
                    $im = imagecreatetruecolor($w, $h);
                    mt_srand($limit === '256M' ? 7 : 8);
                    for ($i = 0; $i < 400; $i++) {
                        imagefilledellipse($im, mt_rand(0, $w), mt_rand(0, $h), mt_rand(50, 900), mt_rand(50, 900), imagecolorallocate($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
                    }
                    for ($i = 0; $i < 20000; $i++) {
                        imagesetpixel($im, mt_rand(0, $w - 1), mt_rand(0, $h - 1), mt_rand(0, 0xFFFFFF));
                    }
                    imagejpeg($im, $file, 90);
                    unset($im);
                }
                $this->put('asset', "{$mp}MP.file_mb", filesize($file) / 1048576);
                $res = $this->child('asset', ['file' => $file], $limit);
                $this->put('asset', "{$mp}MP.{$limit}", ($res['ok'] ?? false) ? round($res['ms']).' ms, '.round($res['peak_mb']).' MB php peak, '.round($res['rss_mb'] ?? 0).' MB rss' : 'REFUSED/FAILED: '.mb_substr((string) ($res['error'] ?? '?'), 0, 160));
            }
        }
    }

    /** Pruning on large tables. */
    private function sectionPrune(array $sizes, int $runs): void
    {
        $deckIds = [];
        for ($i = 0; $i < 50; $i++) {
            $deckIds[] = Deck::create(['project_id' => $this->project->id, 'user_id' => $this->user->id, 'title' => 'Prune '.$i, 'w' => 1920, 'h' => 1080, 'sections' => []])->id;
        }
        $old = now()->subDays(40);
        $rows = [];
        foreach ($deckIds as $d) {
            for ($i = 0; $i < 10_000; $i++) {
                $rows[] = ['deck_id' => $d, 'user_id' => $this->user->id, 'client' => 'c', 'key' => "p-{$d}-{$i}", 'ops' => '[{"op":"el.set","slide":1,"id":"a","p":{"x":1}}]', 'result' => null, 'created_at' => $i < 9_900 ? $old : now()];
                if (count($rows) >= 1000) {
                    DB::table('deck_changes')->insert($rows);
                    $rows = [];
                }
            }
        }
        $this->put('prune', 'changes_rows_before', DeckChange::count());
        $t = hrtime(true);
        Artisan::call('press:prune-changes');
        $this->put('prune', 'prune_changes.ms', (hrtime(true) - $t) / 1e6);
        $this->put('prune', 'changes_rows_after', DeckChange::count());

        $rows = [];
        for ($i = 0; $i < 100_000; $i++) {
            $rows[] = ['deck_id' => $deckIds[$i % 50], 'user_id' => $this->user->id, 'format' => 'pdf', 'status' => $i % 2 ? 'expired' : 'done', 'path' => $i % 2 ? null : "press/exports/x{$i}.pdf",
                'size' => 100, 'attempts' => 1, 'expires_at' => now()->subDays(3), 'created_at' => now()->subDays(10), 'updated_at' => now()->subDays(10)];
            if (count($rows) >= 1000) {
                DB::table('deck_exports')->insert($rows);
                $rows = [];
            }
        }
        $this->put('prune', 'exports_rows_before', DB::table('deck_exports')->count());
        $t = hrtime(true);
        Artisan::call('press:exports-prune');
        $this->put('prune', 'prune_exports.ms', (hrtime(true) - $t) / 1e6);
        $this->put('prune', 'exports_rows_after', DB::table('deck_exports')->count());

        // Sessions: Laravel's database session handler deletes by last_activity.
        $rows = [];
        for ($i = 0; $i < 200_000; $i++) {
            $rows[] = ['id' => "s{$i}", 'user_id' => $this->user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'bench', 'payload' => str_repeat('x', 300), 'last_activity' => $i % 10 === 0 ? time() : time() - 86400 * 10];
            if (count($rows) >= 1000) {
                DB::table('sessions')->insert($rows);
                $rows = [];
            }
        }
        $t = hrtime(true);
        $deleted = DB::table('sessions')->where('last_activity', '<=', time() - 120 * 60)->delete();
        $this->put('prune', 'sessions_delete_old.ms', (hrtime(true) - $t) / 1e6);
        $this->put('prune', 'sessions_deleted', $deleted);

        // Versions: the prune on every snapshot.
        $deck = $this->deck(10);
        $rows = [];
        for ($i = 0; $i < 3000; $i++) {
            $rows[] = ['deck_id' => $deck->id, 'user_id' => $this->user->id, 'kind' => 'auto', 'snapshot' => '{}', 'hash' => 'h', 'slide_count' => 10, 'size' => 2, 'created_at' => now()->subMinutes(3000 - $i)];
        }
        DB::table('deck_versions')->insert($rows);
        $t = hrtime(true);
        $c = new \ReflectionMethod(VersionService::class, 'prune');
        $c->invoke(app(VersionService::class), $deck);
        $this->put('prune', 'versions_prune_3000_autos.ms', (hrtime(true) - $t) / 1e6);
        $this->put('prune', 'versions_left', DB::table('deck_versions')->where('deck_id', $deck->id)->where('kind', 'auto')->count());
    }

    // ============================================================================================================ workers

    /**
     * Run a worker in a child PHP process with an explicit memory limit; returns its JSON line or an error record.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function child(string $worker, array $args, string $memory): array
    {
        $args['db'] = $this->dbPath;
        $process = new Process([PHP_BINARY, '-d', 'memory_limit='.$memory, base_path('artisan'), 'press:bench', '--worker='.$worker, '--args='.json_encode($args), '--no-ansi'], base_path(), null, null, 900);
        $process->run();
        foreach (array_reverse(explode("\n", trim($process->getOutput()))) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && array_key_exists('ok', $decoded)) {
                return $decoded;
            }
        }
        $err = trim($process->getErrorOutput().' '.$process->getOutput());
        preg_match('/Allowed memory size of \d+ bytes exhausted[^\n]*/', $err, $m);

        return ['ok' => false, 'error' => $m[0] ?? ('exit '.$process->getExitCode().': '.mb_substr($err, -300)), 'fatal' => true];
    }

    /**
     * Start $count workers at once and wait for all of them.
     *
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    private function spawn(string $worker, int $count, array $args): array
    {
        $args['db'] = $this->dbPath;
        $procs = [];
        for ($i = 0; $i < $count; $i++) {
            $a = $args + ['index' => $i];
            $a['index'] = $i;
            if (isset($args['decks'])) {
                $a['deck'] = $args['decks'][$i % count($args['decks'])];
            }
            $p = new Process([PHP_BINARY, base_path('artisan'), 'press:bench', '--worker='.$worker, '--args='.json_encode($a), '--no-ansi'], base_path(), null, null, 600);
            $p->start();
            $procs[] = $p;
        }
        $out = [];
        $wall = microtime(true);
        foreach ($procs as $p) {
            $p->wait();
            $res = ['ok' => false, 'error' => trim($p->getErrorOutput())];
            foreach (array_reverse(explode("\n", trim($p->getOutput()))) as $line) {
                $decoded = json_decode($line, true);
                if (is_array($decoded) && array_key_exists('ok', $decoded)) {
                    $res = $decoded;
                    break;
                }
            }
            $out[] = $res;
        }
        $out[0]['wall_ms'] = (microtime(true) - $wall) * 1000;

        return $out;
    }

    /** @param  list<array<string, mixed>>  $results */
    private function summariseWorkers(string $section, string $prefix, array $results): void
    {
        $lat = [];
        $errors = 0;
        $locked = 0;
        foreach ($results as $r) {
            foreach ($r['latencies'] ?? [] as $l) {
                $lat[] = $l;
            }
            $errors += (int) ($r['errors'] ?? 0);
            $locked += (int) ($r['locked'] ?? 0);
            if (($r['ok'] ?? false) !== true) {
                $errors += 1;
            }
        }
        sort($lat);
        $pick = fn (float $p) => $lat === [] ? null : $lat[min(count($lat) - 1, (int) floor($p * count($lat)))];
        $this->put($section, "{$prefix}.requests", count($lat));
        $this->put($section, "{$prefix}.p50_ms", $pick(0.5));
        $this->put($section, "{$prefix}.p95_ms", $pick(0.95));
        $this->put($section, "{$prefix}.max_ms", $lat === [] ? null : end($lat));
        $this->put($section, "{$prefix}.errors", $errors);
        $this->put($section, "{$prefix}.database_locked", $locked);
        $wall = $results[0]['wall_ms'] ?? null;
        if ($wall !== null && $lat !== []) {
            $this->put($section, "{$prefix}.throughput_per_s", count($lat) / ($wall / 1000));
        }
        foreach ($results as $r) {
            if (! empty($r['first_error'])) {
                $this->put($section, "{$prefix}.first_error", $r['first_error']);
                break;
            }
        }
        $queries = array_sum(array_column($results, 'queries'));
        if ($queries > 0 && $lat !== []) {
            $this->put($section, "{$prefix}.queries_per_request", $queries / count($lat));
        }
    }

    /** Peak resident set size of this process in MB: GD and zip buffers live outside PHP's own memory limit. */
    private function rssMb(): float
    {
        $usage = getrusage();
        $kb = (float) ($usage['ru_maxrss'] ?? 0);

        return PHP_OS_FAMILY === 'Darwin' ? $kb / 1048576 : $kb / 1024;
    }

    /** @param  array<string, mixed>  $a */
    private function runWorker(string $worker, array $a): int
    {
        $this->prepareEnvironment((string) $a['db'], fresh: false);
        $this->dbPath = (string) $a['db'];
        $this->user = User::query()->where('email', 'bench@press.test')->firstOrFail();
        Auth::guard('web')->setUser($this->user);
        $out = ['ok' => false];

        try {
            $out = match ($worker) {
                'ops' => $this->workerOps($a),
                'presence' => $this->workerPresence($a),
                'export' => $this->workerExport($a),
                'import' => $this->workerImport($a),
                'present' => $this->workerPresent($a),
                'asset' => $this->workerAsset($a),
                'baseline' => $this->workerBaseline(),
                default => ['ok' => false, 'error' => 'unknown worker'],
            };
        } catch (AssetRejected $e) {
            $out = ['ok' => false, 'error' => $e->getMessage(), 'degraded_cleanly' => true];
        } catch (Throwable $e) {
            $out = ['ok' => false, 'error' => $e::class.': '.$e->getMessage()];
        }

        $out['rss_mb'] = round($this->rssMb(), 1);
        $this->line(json_encode($out));

        return self::SUCCESS;
    }

    /** Peak memory of a bare request in a fresh process: what PHP-FPM holds before any of our data is loaded. @return array<string, mixed> */
    private function workerBaseline(): array
    {
        $this->hit('GET', '/up');
        $this->hit('GET', '/api/user');

        return ['ok' => true, 'peak_mb' => memory_get_peak_usage() / 1048576];
    }

    /** @return array<string, mixed> */
    private function workerOps(array $a): array
    {
        $deck = Deck::withoutGlobalScopes()->findOrFail($a['deck']);
        $slide = Slide::withoutGlobalScopes()->where('deck_id', $deck->id)->orderBy('sort_order')->skip((int) ($a['index'] ?? 0))->first();
        $ids = array_column($slide->els, 'id');
        $applier = app(OpApplier::class);
        $lat = [];
        $locked = 0;
        $errors = 0;
        $this->queries = [];
        for ($i = 0; $i < $a['batches']; $i++) {
            $ops = DeckGenerator::dragOps($slide->id, $ids, (int) $a['ops'], random_int(1, 99));
            $t = hrtime(true);
            try {
                $applier->apply($deck, $this->user, 'w'.($a['index'] ?? 0), 'k-'.getmypid().'-'.$i.'-'.uniqid(), $ops);
            } catch (Throwable $e) {
                $errors++;
                if (str_contains($e->getMessage(), 'locked') || str_contains($e->getMessage(), 'busy')) {
                    $locked++;
                }
            }
            $lat[] = round((hrtime(true) - $t) / 1e6, 1);
        }

        return ['ok' => true, 'latencies' => $lat, 'errors' => $errors, 'locked' => $locked, 'queries' => count($this->queries)];
    }

    /** @return array<string, mixed> */
    private function workerPresence(array $a): array
    {
        $deck = Deck::withoutGlobalScopes()->findOrFail($a['deck']);
        $slideId = Slide::withoutGlobalScopes()->where('deck_id', $deck->id)->value('id');
        $lat = [];
        $errors = 0;
        $locked = 0;
        $this->queries = [];
        $client = 'tab-'.getmypid();
        $firstError = null;
        for ($i = 0; $i < $a['beats']; $i++) {
            $t = hrtime(true);
            try {
                $r = $this->hit('POST', "/api/decks/{$deck->id}/presence", ['client' => $client, 'slide' => $slideId, 'selection' => ['s1e1'], 'cursor' => ['x' => $i, 'y' => $i]]);
                if ($r['status'] >= 400) {
                    $errors++;
                    $firstError ??= $r['status'].' '.mb_substr($r['body'], 0, 160);
                    if (str_contains($r['body'], 'locked')) {
                        $locked++;
                    }
                }
            } catch (Throwable $e) {
                $errors++;
                if (str_contains($e->getMessage(), 'locked')) {
                    $locked++;
                }
            }
            $lat[] = round((hrtime(true) - $t) / 1e6, 1);
        }

        return ['ok' => true, 'latencies' => $lat, 'errors' => $errors, 'locked' => $locked, 'queries' => count($this->queries), 'first_error' => $firstError];
    }

    /** @return array<string, mixed> */
    private function workerExport(array $a): array
    {
        $deck = Deck::withoutGlobalScopes()->with('project')->findOrFail($a['deck']);
        $doc = ExportService::document($deck);
        $want = (int) $a['slides'];
        $slides = $doc['slides'];
        if (count($slides) > $want) {
            $slides = array_slice($slides, 0, $want);
        } elseif (count($slides) < $want) {
            $base = $slides;
            for ($i = count($slides); $i < $want; $i++) {
                $copy = $base[$i % count($base)];
                $copy['id'] = 90_000_000 + $i;
                $slides[] = $copy;
            }
        }
        $doc['slides'] = $slides;
        $resolver = ExportService::resolverFor($deck);

        memory_reset_peak_usage();
        $t = hrtime(true);
        if ($a['format'] === 'pdf') {
            $bytes = (new PdfExporter($resolver))->render($doc, []);
            $size = strlen($bytes);
            if (! empty($a['keep'])) {
                file_put_contents($a['keep'], $bytes);
            }
        } else {
            $tmp = $a['keep'] ?? tempnam(sys_get_temp_dir(), 'bench').'.pptx';
            (new PptxExporter($resolver))->render($doc, $tmp, []);
            $size = (int) filesize($tmp);
            if (empty($a['keep'])) {
                @unlink($tmp);
            }
        }

        return ['ok' => true, 'ms' => (hrtime(true) - $t) / 1e6, 'peak_mb' => memory_get_peak_usage() / 1048576, 'bytes' => $size];
    }

    /** @return array<string, mixed> */
    private function workerImport(array $a): array
    {
        memory_reset_peak_usage();
        $t = hrtime(true);
        $result = PptxImporter::import($a['file'], ['name' => 'bench']);
        $built = ImportedDeckBuilder::build($this->user, $result, 'bench.pptx');

        return ['ok' => true, 'ms' => (hrtime(true) - $t) / 1e6, 'peak_mb' => memory_get_peak_usage() / 1048576, 'slides' => $built['deck']->slides()->count()];
    }

    /** @return array<string, mixed> */
    private function workerPresent(array $a): array
    {
        $deck = Deck::withoutGlobalScopes()->findOrFail($a['deck']);
        memory_reset_peak_usage();
        $t = hrtime(true);
        $built = app(PresentPackage::class)->build($deck);

        return ['ok' => true, 'ms' => (hrtime(true) - $t) / 1e6, 'peak_mb' => memory_get_peak_usage() / 1048576, 'bytes' => strlen($built['html']), 'gz' => strlen((string) gzencode($built['html'], 6)), 'warnings' => count($built['warnings'])];
    }

    /** @return array<string, mixed> */
    private function workerAsset(array $a): array
    {
        $project = Project::withoutGlobalScopes()->firstOrFail();
        memory_reset_peak_usage();
        $t = hrtime(true);
        $asset = app(AssetStore::class)->ingest($project, $this->user, $a['file'], basename($a['file']).uniqid());

        return ['ok' => true, 'ms' => (hrtime(true) - $t) / 1e6, 'peak_mb' => memory_get_peak_usage() / 1048576, 'id' => $asset->id];
    }

    // ============================================================================================================ output

    private function report(): void
    {
        $compare = [];
        if ($this->option('compare') && is_file((string) $this->option('compare'))) {
            $compare = (array) json_decode((string) file_get_contents((string) $this->option('compare')), true);
        }

        $this->newLine();
        foreach ($this->results as $section => $metrics) {
            $this->line("[{$section}]");
            foreach ($metrics as $key => $value) {
                $text = is_scalar($value) || $value === null ? (string) $value : json_encode($value);
                $line = '  '.str_pad($key, 46).' '.mb_substr($text, 0, 220);
                if ($compare !== [] && isset($compare[$section][$key])) {
                    $line .= '   (before: '.mb_substr((string) $compare[$section][$key], 0, 60).')';
                }
                $this->line($line);
            }
        }
        $this->newLine();

        if ($this->option('json')) {
            file_put_contents((string) $this->option('json'), json_encode($this->results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info('Results written to '.$this->option('json'));
        }
        @file_put_contents(storage_path('app/bench/last.json'), json_encode($this->results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
