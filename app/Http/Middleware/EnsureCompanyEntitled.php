<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\InfoDot\CompanyEntitlement;
use App\Support\EntitlementNotice;
use Closure;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * A signed-in person keeps working only while their current company still holds Dot.Press (R-50).
 *
 * It sits on the web and api groups and acts only on a route that needs sign-in, for a person signed in with the
 * browser session whose current team is linked to an InfoDot company. Everything else passes untouched: public and
 * shared presentation links, live presenting, sign-in and sign-out, the InfoDot handoff, health checks, calls that
 * carry a bearer token (machine to machine) and switching company. When InfoDot gives no clear answer the request
 * carries on (see CompanyEntitlement).
 *
 * A personal team, or any team with no InfoDot company behind it, is never asked about at InfoDot. What counts
 * there is what InfoDot said about the person's personal workspace when they signed in, kept in the session:
 * exactly false closes it (individuals hold the free core only, so Dot.Press needs a company that has switched it
 * on); true, or nothing remembered (an older InfoDot, or a session from before this rule), carries on.
 */
class EnsureCompanyEntitled
{
    /** Routes a refused person still needs: leaving, changing company, and the refusal page itself. */
    private const EXEMPT = ['logout', 'current-team.update', 'entitlement.show'];

    /** Session key: what redeem said about the person's personal workspace for this platform (true or false). */
    public const PERSONAL = 'press.entitlement.personal';

    /** Keep, or clear, InfoDot's word on the personal workspace alongside the sign-in. */
    public static function remember(Request $request, ?bool $personalEntitled): void
    {
        if ($personalEntitled === null) {
            $request->session()->forget(self::PERSONAL);
        } else {
            $request->session()->put(self::PERSONAL, $personalEntitled);
        }
    }

    public static function remembered(Request $request): ?bool
    {
        $value = $request->hasSession() ? $request->session()->get(self::PERSONAL) : null;

        return is_bool($value) ? $value : null;
    }

    public function __construct(private CompanyEntitlement $entitlement) {}

    public function handle(Request $request, Closure $next): Response
    {
        $refusal = $this->applies($request) ? self::refusalFor(Auth::guard('web')->user(), $this->entitlement, self::remembered($request)) : null;

        if ($refusal === null) {
            return $next($request);
        }

        // An Inertia visit expects a page object; a full document would open inside Inertia's error overlay. Send the
        // browser to the refusal page instead (409 + X-Inertia-Location: a full page load).
        if ($request->headers->has('X-Inertia')) {
            return Inertia::location(route('entitlement.show'));
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => $refusal['title'],
                'error' => 'platform_not_entitled',
                'status' => $refusal['status'],
                'platforms_url' => $refusal['platforms_url'],
            ], 403);
        }

        return self::page($refusal);
    }

    /**
     * What to tell this person, or null when they may carry on.
     *
     * @param  bool|null  $personalEntitled  what sign-in remembered about the personal workspace, if anything
     * @return array{title: string, message: string, status: string, platforms_url: string}|null
     */
    public static function refusalFor(mixed $user, CompanyEntitlement $entitlement, ?bool $personalEntitled = null): ?array
    {
        if (! $user instanceof User || ! $entitlement->enabled()) {
            return null;
        }

        $team = $user->currentTeam;
        $uuid = $team?->infodot_team_uuid;

        if (blank($uuid)) {
            // No InfoDot company behind this team: never asked about, decided by what sign-in remembered.
            return $personalEntitled === false
                ? EntitlementNotice::for('company_required') + ['status' => 'company_required', 'platforms_url' => $entitlement->platformsUrl()]
                : null;
        }

        $answer = $entitlement->for((string) $uuid);

        if ($answer === null || $answer['entitled']) {
            return null;
        }

        return EntitlementNotice::for($answer['status'], $team->name) + ['status' => $answer['status'], 'platforms_url' => $entitlement->platformsUrl()];
    }

    /**
     * @param  array{title: string, message: string, platforms_url: string, status?: string}  $refusal
     */
    public static function page(array $refusal, ?User $user = null): Response
    {
        $user ??= Auth::guard('web')->user();
        $others = $user instanceof User
            // Only companies InfoDot knows: a personal or local team is closed by the same rule that closed this one.
            ? $user->allTeams()->reject(fn ($team) => $team->id === $user->current_team_id || (($refusal['status'] ?? null) === 'company_required' && blank($team->infodot_team_uuid)))->values()
            : collect();

        return response()
            ->view('errors.entitlement', ['notice' => $refusal, 'platformsUrl' => $refusal['platforms_url'], 'others' => $others], 403)
            ->header('Cache-Control', 'no-store, private');
    }

    private function applies(Request $request): bool
    {
        $route = $request->route();

        if ($route === null || ! $this->entitlement->enabled() || filled($request->bearerToken()) || ! $request->hasSession()) {
            return false;
        }

        if (in_array($route->getName(), self::EXEMPT, true)) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && ($middleware === 'auth' || str_starts_with($middleware, 'auth:') || str_starts_with($middleware, Authenticate::class))) {
                return true;
            }
        }

        return false;
    }
}
