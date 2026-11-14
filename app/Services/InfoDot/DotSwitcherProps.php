<?php

namespace App\Services\InfoDot;

use App\Models\User;
use Dot\Ui\PlatformLogos;
use Dot\Ui\PlatformSwitcher;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The model behind the "Dot Apps" switcher in the header (R-51, the shared dot-ui package).
 *
 * Built on the server from InfoDot's own answer for the person's CURRENT company, cached by the package (ten minutes,
 * sixty seconds after a failure), and handed to the page as a deferred Inertia prop so a page never waits on InfoDot.
 * The company uuid is resolved exactly as the entitlement check resolves it: the current team's `infodot_team_uuid`;
 * a team without one (a personal workspace) gets the personal state and InfoDot is not asked.
 *
 * Nothing here grants access. Every tile is a link to InfoDot's /go, which decides again for the person and hands over
 * the single-use token; the model carries no secret and no token.
 */
class DotSwitcherProps
{
    public const PLATFORM = 'press';

    /**
     * @return array<string, mixed>|null null for a guest, or when the package itself cannot be built
     */
    public function for(mixed $user): ?array
    {
        if (! $user instanceof User) {
            return null;
        }

        $uuid = $user->currentTeam?->infodot_team_uuid;

        try {
            $model = PlatformSwitcher::fromConfig(self::PLATFORM)->for(filled($uuid) ? (string) $uuid : null);
        } catch (Throwable $e) {
            Log::warning('The platform switcher could not be built.', ['reason' => class_basename($e)]);

            return null;
        }

        // The printed size of each logo (the package's PHP knows the originals' dimensions; the Vue component does not).
        foreach ($model['groups'] as $g => $group) {
            foreach ($group['tiles'] as $t => $tile) {
                $model['groups'][$g]['tiles'][$t]['logo_size'] = PlatformLogos::size((string) $tile['key']);
            }
        }

        $model['company_name'] = $user->currentTeam?->name;

        return $model;
    }
}
