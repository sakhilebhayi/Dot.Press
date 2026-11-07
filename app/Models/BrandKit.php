<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use App\Services\Press\Assets\AssetUrls;
use App\Services\Press\ThemeLibrary;
use App\Services\Press\ThemeTokens;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandKit extends Model
{
    use HasTeamScope;

    public const MAX_PER_TEAM = 20;

    protected $fillable = ['team_id', 'name', 'tokens', 'is_default'];

    protected function casts(): array
    {
        return ['tokens' => 'array', 'is_default' => 'boolean'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Converts the kit to a document theme ("Apply brand kit"). Colours with a role fill that slot; the colours
     * without one become the accent, the second accent and the chart palette in order. Fonts map to the
     * heading and body families. Slots the kit does not set come from the slate defaults, and when a kit sets a
     * background but no text colour, the ink is picked for legibility and the neutral slots are derived from it.
     *
     * @return array<string, mixed>
     */
    public function toTheme(): array
    {
        $defaults = ThemeLibrary::defaults();
        $palette = $this->tokens['palette'] ?? [];

        $colours = $defaults['c'];
        $explicit = [];
        $free = [];
        $chart = [];

        foreach ($palette as $colour) {
            $hex = ThemeTokens::canonicalHex((string) $colour['hex']);
            $chart[] = $hex;

            if (isset($colour['role']) && ! isset($explicit[$colour['role']])) {
                $colours[$colour['role']] = $hex;
                $explicit[$colour['role']] = true;
            } else {
                $free[] = $hex;
            }
        }

        foreach (['accent', 'accent2'] as $slot) {
            if (! isset($explicit[$slot]) && $free !== []) {
                $colours[$slot] = array_shift($free);
            }
        }

        if (isset($explicit['bg'])) {
            $light = ThemeTokens::luminance($colours['bg']) > 0.4;
            if (! isset($explicit['ink'])) {
                $colours['ink'] = $light ? '#111827' : '#f9fafb';
            }
            if (! isset($explicit['surface'])) {
                $colours['surface'] = ThemeTokens::mix($colours['bg'], $colours['ink'], 0.06);
            }
            if (! isset($explicit['muted'])) {
                $colours['muted'] = ThemeTokens::mix($colours['bg'], $colours['ink'], 0.62);
            }
            if (! isset($explicit['line'])) {
                $colours['line'] = ThemeTokens::mix($colours['bg'], $colours['ink'], 0.18);
            }
        }

        $colours['chart'] = array_slice(array_values(array_unique(array_merge($chart, $defaults['c']['chart']))), 0, ThemeTokens::CHART_COLOURS);

        $type = $defaults['t'];
        $fonts = array_column($this->tokens['fonts'] ?? [], 'family', 'role');
        $type['heading'] = $fonts['heading'] ?? $fonts['body'] ?? $fonts['accent'] ?? $type['heading'];
        $type['body'] = $fonts['body'] ?? $fonts['heading'] ?? $type['body'];

        return ThemeTokens::toDeckTheme('brand-kit:'.$this->id, $this->name, [
            'c' => $colours,
            't' => $type,
            's' => $defaults['s'],
            'bg' => null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $tokens = ($this->tokens ?? []) + ['palette' => [], 'fonts' => [], 'logos' => []];
        $theme = $this->toTheme();

        $logos = [];
        if ($tokens['logos'] !== []) {
            $byId = Asset::withoutGlobalScopes()->whereIn('id', $tokens['logos'])->get()->keyBy('id');
            foreach ($tokens['logos'] as $id) {
                if ($asset = $byId->get($id)) {
                    $payload = AssetUrls::payload($asset);
                    $logos[] = array_intersect_key($payload, array_flip(['id', 'name', 'mime', 'width', 'height', 'url', 'thumb']));
                }
            }
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_default' => $this->is_default,
            'tokens' => $tokens,
            'logo_assets' => $logos,
            'theme' => $theme,
            'warnings' => ThemeTokens::validate($theme)['warnings'],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
