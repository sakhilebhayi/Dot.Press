<?php

namespace Tests\Support;

/**
 * Runs the small inspection scripts in tests/Fixtures/Press with a Python that has python-pptx and pypdf, when one exists.
 * Point PRESS_PYTHON at it, or create /tmp/press-venv (python3 -m venv, pip install python-pptx pypdf pillow).
 */
final class PressPython
{
    private static ?string $bin = null;

    private static bool $checked = false;

    public static function bin(): ?string
    {
        if (self::$checked) {
            return self::$bin;
        }
        self::$checked = true;
        foreach (array_filter([getenv('PRESS_PYTHON') ?: null, '/tmp/press-venv/bin/python']) as $candidate) {
            if (is_executable($candidate)) {
                $out = [];
                $code = 1;
                exec(escapeshellarg($candidate).' -I -c '.escapeshellarg('import pptx, pypdf').' 2>&1', $out, $code);
                if ($code === 0) {
                    return self::$bin = $candidate;
                }
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public static function inspect(string $script, string $file): array
    {
        $bin = self::bin();
        $cmd = escapeshellarg($bin).' -I -W ignore '.escapeshellarg(__DIR__.'/../Fixtures/Press/'.$script).' '.escapeshellarg($file).' 2>&1';
        $out = shell_exec($cmd);
        $json = json_decode((string) $out, true);
        if (! is_array($json)) {
            throw new \RuntimeException('Inspection script failed: '.$out);
        }

        return $json;
    }
}
