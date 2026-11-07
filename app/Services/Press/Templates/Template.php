<?php

namespace App\Services\Press\Templates;

/**
 * One built-in template. meta() describes it; compose() adds its slides through the Compose helper, which draws
 * every slide from the built-in layouts.
 */
interface Template
{
    /**
     * key, name, category, blurb (names the narrative), narrative, theme (a ThemeLibrary id), tags, and optionally
     * transition ({type, dir, dur, ease}), cover (band, split or rule) and icon (an id from the icon set).
     *
     * @return array<string, mixed>
     */
    public static function meta(): array;

    public static function compose(Compose $c): void;
}
