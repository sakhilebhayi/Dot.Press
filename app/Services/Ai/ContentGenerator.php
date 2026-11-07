<?php

namespace App\Services\Ai;

use App\Services\Press\Intel\JsonShape;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ContentGenerator
{
    public function generateSlide(string $prompt): array
    {
        $provider = config('ai.provider', 'mock');

        if ($provider === 'anthropic') {
            return $this->generateSlideWithAnthropic($prompt);
        }

        return $this->contentSlideMock($this->headline($prompt), "Generated from prompt:\n\n".$prompt);
    }

    /**
     * Generate a full themed deck from a topic/outline in one call, instead
     * of one slide at a time -- a title slide followed by up to
     * $slideCount - 1 body slides. If the prompt reads as an outline (one
     * topic per line), each line becomes its own slide; otherwise the
     * remaining slides are generically titled sections built around the
     * single topic, all sharing the same element styling for a consistent
     * look across the deck.
     *
     * @return array{slides: list<array{title: string, elements: array}>, usage: array}
     */
    public function generateDeck(string $prompt, int $slideCount): array
    {
        $provider = config('ai.provider', 'mock');

        if ($provider === 'anthropic') {
            return $this->generateDeckWithAnthropic($prompt, $slideCount);
        }

        return $this->generateDeckMock($prompt, $slideCount);
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

    private function generateDeckMock(string $prompt, int $slideCount): array
    {
        [$deckTopic, $sections] = $this->buildOutline($prompt, $slideCount);

        $slides = [$this->titleSlideMock($deckTopic, $prompt)];

        foreach ($sections as $section) {
            $slides[] = $this->contentSlideMock($section, "Notes for \"{$section}\", part of: {$deckTopic}");
        }

        return [
            'slides' => $slides,
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
        ];
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

        $sections = collect(range(0, $bodyCount - 1))
            ->map(fn (int $i) => $bodyLines->get($i) ?? 'Section '.($i + 1))
            ->all();

        return [$deckTopic, $sections];
    }

    private function headline(string $prompt): string
    {
        $title = trim(mb_substr($prompt, 0, 70));

        return $title !== '' ? $title : 'New AI Slide';
    }

    private function titleSlideMock(string $topic, string $subtitleSource): array
    {
        $subtitle = trim(mb_substr($subtitleSource, 0, 160));

        return [
            'title' => $topic,
            'elements' => [
                $this->textElement('ai-title-'.uniqid(), $topic, [
                    'x' => 90, 'y' => 260, 'width' => 1100, 'height' => 140,
                    'fontSize' => 64, 'fill' => '#0f172a', 'fontStyle' => 'bold',
                    'textAlign' => 'center', 'lineHeight' => 1.2,
                ]),
                $this->textElement('ai-subtitle-'.uniqid(), $subtitle, [
                    'x' => 190, 'y' => 410, 'width' => 900, 'height' => 80,
                    'fontSize' => 26, 'fill' => '#475569', 'fontStyle' => 'normal',
                    'textAlign' => 'center', 'lineHeight' => 1.3,
                ]),
            ],
        ];
    }

    private function contentSlideMock(string $title, string $body): array
    {
        return [
            'title' => $title,
            'elements' => [
                $this->textElement('ai-title-'.uniqid(), $title, [
                    'x' => 90, 'y' => 80, 'width' => 1100, 'height' => 120,
                    'fontSize' => 54, 'fill' => '#0f172a', 'fontStyle' => 'bold',
                    'textAlign' => 'left', 'lineHeight' => 1.2,
                ]),
                $this->textElement('ai-body-'.uniqid(), $body, [
                    'x' => 90, 'y' => 235, 'width' => 780, 'height' => 320,
                    'fontSize' => 26, 'fill' => '#334155', 'fontStyle' => 'normal',
                    'textAlign' => 'left', 'lineHeight' => 1.4,
                ]),
                [
                    'id' => 'ai-visual-'.uniqid(),
                    'type' => 'rect',
                    'x' => 900,
                    'y' => 235,
                    'width' => 290,
                    'height' => 320,
                    'rotation' => 0,
                    'fill' => '#dbeafe',
                    'stroke' => '#1d4ed8',
                    'strokeWidth' => 2,
                    'radius' => 14,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $layout
     */
    private function textElement(string $id, string $text, array $layout): array
    {
        return array_merge([
            'id' => $id,
            'type' => 'text',
            'rotation' => 0,
            'text' => $text,
            'textIndent' => 0,
            'richContent' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => [[
                        'type' => 'text',
                        'text' => $text,
                    ]],
                ]],
            ],
        ], $layout);
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

    private function generateSlideWithAnthropic(string $prompt): array
    {
        $instruction = <<<'TXT'
You are generating one presentation slide.
Return valid JSON with this exact shape:
{
  "title": "...",
  "elements": [
    {
      "type": "text",
      "x": 0,
      "y": 0,
      "width": 0,
      "height": 0,
      "rotation": 0,
      "text": "...",
      "fontSize": 0,
      "fill": "#000000",
      "fontStyle": "normal",
      "textAlign": "left",
      "lineHeight": 1.3,
      "textIndent": 0,
      "richContent": {"type":"doc","content":[{"type":"paragraph","content":[{"type":"text","text":"..."}]}]}
    }
  ]
}
Use a 1280x720 coordinate system.
TXT;

        $payload = $this->callAnthropic($instruction, $prompt);
        $json = $this->extractJson($payload);

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('AI slide response is not valid JSON.');
        }

        return [
            'title' => Arr::get($decoded, 'title', 'AI Slide'),
            'elements' => Arr::get($decoded, 'elements', []),
            'usage' => Arr::get($payload, 'usage', []),
        ];
    }

    private function generateDeckWithAnthropic(string $prompt, int $slideCount): array
    {
        $instruction = <<<TXT
You are generating a full presentation deck of exactly {$slideCount} slides, sharing one consistent visual theme (same color palette and font sizes for the same role across every slide -- title slides styled like title slides, body slides styled like body slides).
Return valid JSON with this exact shape:
{
  "slides": [
    {
      "title": "...",
      "elements": [
        {
          "type": "text",
          "x": 0, "y": 0, "width": 0, "height": 0, "rotation": 0,
          "text": "...", "fontSize": 0, "fill": "#000000", "fontStyle": "normal",
          "textAlign": "left", "lineHeight": 1.3, "textIndent": 0,
          "richContent": {"type":"doc","content":[{"type":"paragraph","content":[{"type":"text","text":"..."}]}]}
        }
      ]
    }
  ]
}
The first slide should be a title slide. Use a 1280x720 coordinate system.
TXT;

        $payload = $this->callAnthropic($instruction, $prompt);
        $json = $this->extractJson($payload);

        $decoded = json_decode($json, true);

        if (! is_array($decoded) || ! is_array(Arr::get($decoded, 'slides'))) {
            throw new RuntimeException('AI deck response is not valid JSON.');
        }

        $slides = Collection::make(Arr::get($decoded, 'slides', []))
            ->take($slideCount)
            ->map(fn ($slide) => [
                'title' => Arr::get($slide, 'title', 'AI Slide'),
                'elements' => Arr::get($slide, 'elements', []),
            ])
            ->values()
            ->all();

        if (empty($slides)) {
            throw new RuntimeException('AI deck response contained no slides.');
        }

        return [
            'slides' => $slides,
            'usage' => Arr::get($payload, 'usage', []),
        ];
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
