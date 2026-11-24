<?php

namespace App\Services\InfoDot;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks InfoDot whether a company still holds this platform (R-50), for a person who is already signed in.
 *
 * The answer is kept per company for sixty seconds, so a switch made at InfoDot (a platform put in maintenance, R-61)
 * takes effect within about a minute. It also says whether InfoDot has the platform switched on (`available`, `message`);
 * a row without `available` (an older InfoDot) is never read as a switch-off. Every clear answer is also remembered
 * for six hours. Anything other than a clear answer (InfoDot unreachable or erroring, the secret wrong or not set, a
 * body this app cannot read, this platform's own row missing) uses the last clear answer under six hours old, so a
 * platform last told "unavailable" stays unavailable. With none it is "unknown": the caller carries on as before, the
 * failure is logged once and remembered for sixty seconds. An outage or a misconfiguration at InfoDot never locks a
 * paying company out, and "unknown" is never shown to anyone as "active". Only the company's uuid leaves this app.
 */
class CompanyEntitlement
{
    public const KNOWN_FOR = 60;

    public const UNKNOWN_FOR = 60;

    /** How long the last clear answer is used when InfoDot gives no clear answer. */
    public const LAST_KNOWN_FOR = 21600;

    public function enabled(): bool
    {
        return (bool) config('ecosystem.infodot.entitlement_check', true);
    }

    public function platform(): string
    {
        return (string) config('ecosystem.infodot.platform', 'press');
    }

    /** InfoDot's page for this platform, where a company switches it on. */
    public function platformsUrl(): string
    {
        return rtrim((string) config('ecosystem.platforms.infodot.url', 'https://infodot.co.za'), '/').'/platforms/'.rawurlencode($this->platform());
    }

    /**
     * @return array{entitled: bool, status: string, available: bool, message: string|null}|null null when InfoDot gave no clear answer
     */
    public function for(string $teamUuid): ?array
    {
        $key = 'press:entitlement:'.$this->platform().':'.sha1($teamUuid);
        $cached = Cache::get($key);

        if (is_array($cached) && array_key_exists('answer', $cached)) {
            return $cached['answer'];
        }

        [$answer, $why] = $this->ask($teamUuid);

        if ($answer !== null) {
            Cache::put($key.':last', ['answer' => $answer, 'at' => time()], self::LAST_KNOWN_FOR);
        } else {
            // Once per window: the next request inside it reads the remembered answer and stays quiet.
            Log::warning('Could not confirm the company still holds this platform; carrying on.', ['platform' => $this->platform(), 'reason' => $why]);

            $last = Cache::get($key.':last');

            if (is_array($last) && is_array($last['answer'] ?? null) && time() - (int) ($last['at'] ?? 0) < self::LAST_KNOWN_FOR) {
                $answer = $last['answer'];
            }
        }

        Cache::put($key, ['answer' => $answer], $answer === null ? self::UNKNOWN_FOR : self::KNOWN_FOR);

        return $answer;
    }

    /**
     * @return array{0: array{entitled: bool, status: string, available: bool, message: string|null}|null, 1: string}
     */
    private function ask(string $teamUuid): array
    {
        $secret = (string) config('ecosystem.infodot.secret', '');
        $url = $this->endpoint($teamUuid);

        if ($secret === '' || $url === null) {
            return [null, 'service secret or InfoDot address not set'];
        }

        try {
            $response = Http::withToken($secret)
                ->acceptJson()
                ->withHeaders(['X-Dot-Platform' => $this->platform()])
                ->timeout((int) config('ecosystem.infodot.timeout', 5))
                ->get($url, ['platform' => $this->platform()]);
        } catch (Throwable $e) {
            return [null, 'unreachable ('.class_basename($e).')'];
        }

        if (! $response->successful()) {
            return [null, 'status '.$response->status()];
        }

        $body = $response->json();
        $rows = is_array($body) ? ($body['platforms'] ?? null) : null;

        if (! is_array($rows)) {
            return [null, 'unreadable answer'];
        }

        foreach ($rows as $row) {
            if (is_array($row) && ($row['key'] ?? null) === $this->platform()) {
                if (! is_bool($row['entitled'] ?? null)) {
                    return [null, 'unreadable answer'];
                }

                return [[
                    'entitled' => $row['entitled'],
                    'status' => is_string($row['status'] ?? null) ? $row['status'] : '',
                    // R-61: an InfoDot from before contract 2 gives no `available`, which is not a switch-off.
                    'available' => ($row['available'] ?? true) !== false,
                    'message' => is_string($row['message'] ?? null) && $row['message'] !== '' ? mb_substr($row['message'], 0, 400) : null,
                ], 'answered'];
            }
        }

        return [null, 'this platform is not in the answer'];
    }

    /** INFODOT_PLATFORMS_URL when set, otherwise next to the redeem endpoint. `{uuid}` is the company. */
    private function endpoint(string $teamUuid): ?string
    {
        $template = (string) config('ecosystem.infodot.platforms_url', '');

        if ($template === '') {
            $redeem = (string) config('ecosystem.infodot.redeem_url', '');

            if (! str_ends_with($redeem, '/redeem')) {
                return null;
            }

            $template = substr($redeem, 0, -strlen('/redeem')).'/companies/{uuid}/platforms';
        }

        return str_contains($template, '{uuid}') ? str_replace('{uuid}', rawurlencode($teamUuid), $template) : null;
    }
}
