<?php

namespace App\Services\Press\Assets;

/**
 * Walks the block structure of a GIF. That tells us three things GD cannot: whether the file is complete
 * (it must end with the trailer byte), how many frames it holds (more than one means animated) and where the
 * real data ends, so anything appended after the trailer can be cut off.
 */
final class GifInspector
{
    /**
     * @return array{frames: int, end: int}
     */
    public static function inspect(string $bytes): array
    {
        $length = strlen($bytes);

        if ($length < 14 || ! in_array(substr($bytes, 0, 6), ['GIF87a', 'GIF89a'], true)) {
            throw new AssetRejected('This is not a valid GIF image.');
        }

        $flags = ord($bytes[10]);
        $position = 13 + (($flags & 0x80) ? 3 * (1 << (($flags & 0x07) + 1)) : 0);
        $frames = 0;

        while ($position < $length) {
            $block = ord($bytes[$position]);

            if ($block === 0x3B) {
                if ($frames === 0) {
                    throw new AssetRejected('This GIF has no image data.');
                }

                return ['frames' => $frames, 'end' => $position + 1];
            }

            if ($block === 0x21) {
                $position = self::skipSubBlocks($bytes, $position + 2);
            } elseif ($block === 0x2C) {
                if ($position + 10 > $length) {
                    break;
                }
                $local = ord($bytes[$position + 9]);
                $position += 10 + (($local & 0x80) ? 3 * (1 << (($local & 0x07) + 1)) : 0) + 1;
                $position = self::skipSubBlocks($bytes, $position);
                $frames++;
            } else {
                throw new AssetRejected('This GIF is damaged.');
            }

            if ($frames > 2000) {
                throw new AssetRejected('This GIF has too many frames.');
            }
        }

        throw new AssetRejected('This GIF is damaged or was cut short.');
    }

    private static function skipSubBlocks(string $bytes, int $position): int
    {
        $length = strlen($bytes);

        while ($position < $length) {
            $size = ord($bytes[$position]);
            $position += 1 + $size;

            if ($size === 0) {
                return $position;
            }
        }

        throw new AssetRejected('This GIF is damaged or was cut short.');
    }
}
