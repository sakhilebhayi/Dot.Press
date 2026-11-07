<?php

namespace App\Services\Ai;

use App\Services\Press\Intel\JsonShape;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ContentGenerator
{
    public function __construct(private readonly SlideComposer $composer = new SlideComposer) {}

    /**
     * One slide as spec elements sized for a deck of $w x $h units.
     *
     * @return array{title: string, layout: string, notes: string|null, els: list<array<string, mixed>>, usage: array}
     */
    public function generateSlide(string $prompt, int $w = 1920, int $h = 1080): array
    {
        if (config('ai.provider', 'mock') === 'anthropic') {
            return $this->generateSlideWithAnthropic($prompt, $w, $h);
        }

        return $this->composer->compose($this->mockSlideSpec($prompt), $w, $h) + ['usage' => ['input_tokens' => 0, 'output_tokens' => 0]];
    }

    /**
     * A full deck from a topic or outline in one call: a title slide followed by up to $slideCount - 1 body slides. If
     * the prompt reads as an outline (one topic per line), each line becomes a slide; otherwise the remaining slides
     * are generically titled sections around the single topic.
     *
     * @return array{slides: list<array{title: string, layout: string, notes: string|null, els: list<array<string, mixed>>}>, usage: array}
     */
    public function generateDeck(string $prompt, int $slideCount, int $w = 1920, int $h = 1080): array
    {
        if (config('ai.provider', 'mock') === 'anthropic') {
            return $this->generateDeckWithAnthropic($prompt, $slideCount, $w, $h);
        }

        return $this->generateDeckMock($prompt, $slideCount, $w, $h);
    }

    public function rewriteText(string $input, string $mode, ?string $tone = null): string
    {
        $provider = config('ai.provider', 'mock');

        if ($provider === 'anthropic') {
            return $this->rewriteTextWithAnthropic($input, $mode, $tone);
        }

        return $this->rewriteTextMock($input, $mode, $tone);
    }

    /**
     * True only when a real language model can be called: the provider is `anthropic` and an API key
     * is configured. With the default `mock` provider every Dot.Press intelligence feature runs its
     * deterministic local rules instead and says so (`mode: "local"`).
     */
    public function modelAvailable(): bool
    {
        return config('ai.provider', 'mock') === 'anthropic'
            && filled(config('ai.anthropic.api_key'))
            && (bool) config('press-ai.model_mode', true);
    }

    /**
     * Ask the model for one JSON document and validate it against a schema (the subset JsonShape
     * understands). One request, one retry: a timeout, a 5xx, a 429, text that is not JSON, or JSON
     * that does not fit the schema is tried once more (the second time telling the model what was
     * wrong). It never throws and never returns unvalidated data: on failure `ok` is false and the
     * caller falls back to local mode and reports `degraded: true`.
     *
     * @param  array<string, mixed>  $schema
     * @param  array{max_tokens?: int, timeout?: int}  $options
     * @return array{ok: bool, data: array<string, mixed>|null, usage: array{input_tokens: int, output_tokens: int}, error: string|null, attempts: int, latency_ms: int}
     */
    public function completeJson(string $system, string $user, array $schema, array $options = []): array
    {
        $usage = ['input_tokens' => 0, 'output_tokens' => 0];
        $started = microtime(true);
        $error = null;
        $attempts = 0;
        $hint = '';

        if (! $this->modelAvailable()) {
            return ['ok' => false, 'data' => null, 'usage' => $usage, 'error' => 'No language model is configured.', 'attempts' => 0, 'latency_ms' => 0];
        }

        $system .= "\n\nReply with one JSON object only: no prose, no markdown fences. It must fit this JSON schema:\n".json_encode($schema, JSON_UNESCAPED_SLASHES);

        for ($try = 0; $try < 2; $try++) {
            $attempts++;

            try {
                $response = Http::timeout((int) ($options['timeout'] ?? config('ai.anthropic.json_timeout_seconds', 45)))
                    ->withHeaders([
                        'x-api-key' => (string) config('ai.anthropic.api_key'),
                        'anthropic-version' => '2023-06-01',
                        'content-type' => 'application/json',
                    ])
                    ->post('https://api.anthropic.com/v1/messages', [
                        'model' => config('ai.anthropic.model'),
                        'max_tokens' => (int) ($options['max_tokens'] ?? config('ai.anthropic.json_max_tokens', 4000)),
                        'system' => $system,
                        'messages' => [['role' => 'user', 'content' => $user.$hint]],
                    ]);
            } catch (\Throwable $e) {
                $error = 'The model request failed: '.class_basename($e);

                continue;
            }

            $usage['input_tokens'] += (int) Arr::get($response->json() ?? [], 'usage.input_tokens', 0);
            $usage['output_tokens'] += (int) Arr::get($response->json() ?? [], 'usage.output_tokens', 0);

            if (! $response->successful()) {
                $error = 'The model returned status '.$response->status().'.';
                // A bad key or a malformed request will not improve on a retry.
                if ($response->status() >= 400 && $response->status() < 500 && $response->status() !== 429) {
                    break;
                }

                continue;
            }

            try {
                $json = $this->extractJson($response->json() ?? []);
            } catch (RuntimeException $e) {
                $error = 'The model reply was not JSON.';
                $hint = "\n\nYour previous reply was not a single valid JSON object. Reply with the JSON object only.";

                continue;
            }
            $data = json_decode($json, true);
            if (! is_array($data)) {
                $error = 'The model reply was not valid JSON.';
                $hint = "\n\nYour previous reply was not valid JSON. Reply with the JSON object only.";

                continue;
            }
            $problems = JsonShape::validate($data, $schema);
            if ($problems !== []) {
                $error = 'The model reply did not fit the expected shape.';
                $hint = "\n\nYour previous reply did not fit the schema: ".implode('; ', array_slice($problems, 0, 5)).'. Fix it and reply with the JSON object only.';

                continue;
            }

            return ['ok' => true, 'data' => $data, 'usage' => $usage, 'error' => null, 'attempts' => $attempts, 'latency_ms' => (int) round((microtime(true) - $started) * 1000)];
        }

        return ['ok' => false, 'data' => null, 'usage' => $usage, 'error' => $error, 'attempts' => $attempts, 'latency_ms' => (int) round((microtime(true) - $started) * 1000)];
    }

    private function generateDeckMock(string $prompt, int $slideCount, int $w, int $h): array
    {
        [$deckTopic, $sections] = $this->buildOutline($prompt, $slideCount);
        $note = 'Placeholder text from the mock AI provider. Set AI_PROVIDER=anthropic for written content.';

        $slides = [$this->composer->compose([
            'layout' => 'title', 'title' => $deckTopic, 'subtitle' => trim(mb_substr($prompt, 0, 160)), 'notes' => $note,
        ], $w, $h)];

        foreach ($sections as $section) {
            $slides[] = $this->composer->compose([
                'layout' => 'bullets', 'title' => $section,
                'bullets' => ["First point about {$section}", "Second point about {$section}", "Third point about {$section}"],
                'notes' => $note,
            ], $w, $h);
        }

        return ['slides' => $slides, 'usage' => ['input_tokens' => 0, 'output_tokens' => 0]];
    }

    /**
     * @return array<string, mixed>
     */
    private function mockSlideSpec(string $prompt): array
    {
        $lines = collect(preg_split('/\r\n|\r|\n/', trim($prompt)))
            ->map(fn ($line) => trim((string) preg_replace('/^[-*\d.\)]+\s*/', '', $line)))
            ->filter(fn ($line) => $line !== '')
            ->values();

        $title = trim(mb_substr((string) $lines->first(), 0, 70)) ?: 'New AI Slide';
        $note = 'Placeholder text from the mock AI provider. Set AI_PROVIDER=anthropic for written content.';

        if ($lines->count() > 1) {
            return ['layout' => 'bullets', 'title' => $title, 'bullets' => $lines->slice(1, 6)->all(), 'notes' => $note];
        }

        return ['layout' => 'title', 'title' => $title, 'subtitle' => trim(mb_substr((string) $lines->first(), 70, 160)), 'notes' => $note];
    }

    /**
     * @return array{0: string, 1: list<string>} [deck topic, body slide titles]
     */
    private function buildOutline(string $prompt, int $slideCount): array
    {
        $lines = collect(preg_split('/\r\n|\r|\n/', trim($prompt)))
            ->map(fn ($line) => trim((string) preg_replace('/^[-*\d.\)]+\s*/', '', $line)))
            ->filter(fn ($line) => $line !== '')
            ->values();

        $deckTopic = $lines->first() ?? 'AI Generated Deck';
        $bodyLines = $lines->slice(1)->values();
        $bodyCount = max(0, $slideCount - 1);

        $sections = $bodyCount === 0 ? [] : collect(range(0, $bodyCount - 1))
            ->map(fn (int $i) => $bodyLines->get($i) ?? 'Section '.($i + 1))
            ->all();

        return [$deckTopic, $sections];
    }

    private function generateSlideWithAnthropic(string $prompt, int $w, int $h): array
    {
        $payload = $this->callAnthropic($this->slideInstruction(1), $prompt);
        $decoded = json_decode($this->extractJson($payload), true);

        $spec = is_array($decoded) ? ($decoded['slides'][0] ?? $decoded) : null;
        if (! is_array($spec)) {
            throw new RuntimeException('AI slide response is not valid JSON.');
        }

        return $this->composer->compose($spec, $w, $h) + ['usage' => Arr::get($payload, 'usage', [])];
    }

    private function generateDeckWithAnthropic(string $prompt, int $slideCount, int $w, int $h): array
    {
        $payload = $this->callAnthropic($this->slideInstruction($slideCount), $prompt);
        $decoded = json_decode($this->extractJson($payload), true);

        if (! is_array($decoded) || ! is_array(Arr::get($decoded, 'slides'))) {
            throw new RuntimeException('AI deck response is not valid JSON.');
        }

        $slides = Collection::make(Arr::get($decoded, 'slides', []))
            ->filter(fn ($spec) => is_array($spec))
            ->take($slideCount)
            ->map(fn ($spec) => $this->composer->compose($spec, $w, $h))
            ->values()
            ->all();

        if (empty($slides)) {
            throw new RuntimeException('AI deck response contained no slides.');
        }

        return ['slides' => $slides, 'usage' => Arr::get($payload, 'usage', [])];
    }

    /**
     * The model writes content and picks a layout; the server places it, so no coordinates are asked for.
     */
    private function slideInstruction(int $count): string
    {
        return <<<TXT
You are writing {$count} presentation slide(s). Reply with valid JSON only, in this exact shape:
{"slides":[{"layout":"title|bullets|quote|sections","title":"...","subtitle":"... (title layout)","bullets":["... (bullets layout, at most 6, short)"],"quote":"... (quote layout)","attribution":"... (quote layout)","sections":[{"heading":"...","body":"..."}],"notes":"speaker notes, optional"}]}
Use the title layout for an opening slide, bullets for lists, quote for one memorable statement and sections (2 to 4 columns) for comparisons. Keep every text short enough to read on a slide. Write only what the brief supports.
TXT;
    }

    private function rewriteTextMock(string $input, string $mode, ?string $tone = null): string
    {
        return match ($mode) {
            'shorten' => mb_strimwidth($input, 0, 220, '...'),
            'expand' => trim($input).' This point matters because it drives clarity, alignment, and decision quality for the audience.',
            'rephrase' => 'In other words: '.trim($input),
            'tone' => $tone ? "[{$tone} tone] ".trim($input) : trim($input),
            default => trim($input),
        };
    }

    private function rewriteTextWithAnthropic(string $input, string $mode, ?string $tone): string
    {
        $instruction = 'Rewrite the text according to the requested mode. Return plain text only.';
        $prompt = "Mode: {$mode}\nTone: ".($tone ?? 'default')."\n\nText:\n{$input}";

        $payload = $this->callAnthropic($instruction, $prompt);

        return trim(Arr::get($payload, 'content.0.text', $input));
    }

    private function callAnthropic(string $system, string $prompt): array
    {
        $apiKey = config('ai.anthropic.api_key');

        if (! $apiKey) {
            throw new RuntimeException('ANTHROPIC_API_KEY is missing.');
        }

        $response = Http::timeout((int) config('ai.anthropic.timeout_seconds', 20))
            ->withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => config('ai.anthropic.model'),
                'max_tokens' => (int) config('ai.anthropic.max_tokens', 1200),
                'system' => $system,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Anthropic request failed with status '.$response->status().'.');
        }

        return $response->json();
    }

    private function extractJson(array $payload): string
    {
        $content = Arr::get($payload, 'content.0.text', '');

        $jsonStart = strpos($content, '{');
        $jsonEnd = strrpos($content, '}');

        if ($jsonStart === false || $jsonEnd === false || $jsonEnd <= $jsonStart) {
            throw new RuntimeException('AI response did not include JSON.');
        }

        return substr($content, $jsonStart, $jsonEnd - $jsonStart + 1);
    }
}
