<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Production is a single SQLite file on a shared host, written at the same
 * time by web requests, cron's queue:work and cron's schedule:run. These
 * tests pin the connection settings that make that safe: without a busy
 * timeout a second writer fails at once with "database is locked", and
 * without WAL a writer blocks every reader.
 */
class SqliteProductionConfigTest extends TestCase
{
    public function test_the_sqlite_connection_carries_the_production_settings(): void
    {
        $config = config('database.connections.sqlite');

        $this->assertTrue($config['foreign_key_constraints']);
        $this->assertSame(5000, (int) $config['busy_timeout']);
        $this->assertSame('wal', strtolower((string) $config['journal_mode']));
        $this->assertSame('normal', strtolower((string) $config['synchronous']));
        $this->assertSame('IMMEDIATE', strtoupper((string) $config['transaction_mode']));
    }

    public function test_a_file_database_actually_applies_the_pragmas(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'press-sqlite-');

        try {
            config(['database.connections.press_file' => array_merge(
                config('database.connections.sqlite'),
                ['database' => $file],
            )]);

            $db = DB::connection('press_file');

            $this->assertSame(5000, (int) $db->selectOne('pragma busy_timeout')->timeout);
            $this->assertSame('wal', $db->selectOne('pragma journal_mode')->journal_mode);
            $this->assertSame(1, (int) $db->selectOne('pragma synchronous')->synchronous); // 1 = NORMAL
            $this->assertSame(1, (int) $db->selectOne('pragma foreign_keys')->foreign_keys);
        } finally {
            DB::purge('press_file');
            foreach ([$file, $file.'-wal', $file.'-shm'] as $path) {
                @unlink($path);
            }
        }
    }

    public function test_the_application_defaults_to_sqlite_on_the_database_drivers(): void
    {
        $config = $this->readConfigWithoutEnvironment('database');

        $this->assertSame('sqlite', $config['default']);
        $this->assertSame('database', $this->readConfigWithoutEnvironment('queue')['default']);
        $this->assertSame('database', $this->readConfigWithoutEnvironment('cache')['default']);
        $this->assertSame('database', $this->readConfigWithoutEnvironment('session')['driver']);
    }

    /**
     * Evaluate a config file as if no environment variable were set, so the
     * test sees the file's own defaults rather than phpunit.xml's overrides.
     */
    private function readConfigWithoutEnvironment(string $name): array
    {
        $keys = [
            'DB_CONNECTION', 'DB_DATABASE', 'DB_URL', 'QUEUE_CONNECTION', 'CACHE_STORE', 'SESSION_DRIVER',
            'DB_BUSY_TIMEOUT', 'DB_JOURNAL_MODE', 'DB_SYNCHRONOUS', 'DB_TRANSACTION_MODE', 'DB_FOREIGN_KEYS',
        ];
        $saved = [];

        foreach ($keys as $key) {
            $saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }

        try {
            return require config_path($name.'.php');
        } finally {
            foreach ($saved as $key => [$env, $server, $getenv]) {
                if ($env !== null) {
                    $_ENV[$key] = $env;
                }
                if ($server !== null) {
                    $_SERVER[$key] = $server;
                }
                if ($getenv !== false) {
                    putenv("$key=$getenv");
                }
            }
        }
    }
}
