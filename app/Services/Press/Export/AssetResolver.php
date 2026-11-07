<?php

namespace App\Services\Press\Export;

/**
 * Hands the exporters the bytes of an asset. The exporters never touch storage themselves, so tests can pass
 * an in-memory implementation and the app passes the database one.
 */
interface AssetResolver
{
    /**
     * @return array{bytes: string, mime: string, width: ?int, height: ?int, name: string}|null
     */
    public function find(int $id): ?array;
}
