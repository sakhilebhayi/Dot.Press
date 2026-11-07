<?php

namespace App\Services\Press\Import;

/**
 * What a PPTX import produced: the deck and slides in the document shape (spec sections 4 to 6), the pictures found
 * (still to be stored as assets) and a report of everything that could not be carried over.
 *
 * Until withAssets() runs, a picture element's `src` is `{import: "<part name>"}` and the bytes are in $media.
 */
final class ImportResult
{
    /**
     * @param  array<string, mixed>  $deck
     * @param  list<array<string, mixed>>  $slides
     * @param  array<string, array{name: string, mime: string, size: int, bytes: string}>  $media
     * @param  list<array{slide: ?int, element: ?string, kind: string, message: string}>  $report
     */
    public function __construct(
        public array $deck,
        public array $slides,
        public array $media = [],
        public array $report = [],
    ) {}

    /** The document in the shape DeckDocument::toArray produces. @return array{deck: array<string, mixed>, slides: list<array<string, mixed>>} */
    public function toDocument(): array
    {
        return ['deck' => $this->deck, 'slides' => $this->slides];
    }

    /**
     * Store every picture through $store(bytes, mime, name): ?int and point the elements at the new asset ids. The bytes
     * are released as they are stored. A picture the store refuses (null) is removed from its slide and reported.
     *
     * @param  callable(string, string, string): ?int  $store
     */
    public function withAssets(callable $store): static
    {
        $ids = [];
        foreach ($this->media as $rel => $m) {
            $ids[$rel] = $store($m['bytes'], $m['mime'], $m['name']);
            $this->media[$rel]['bytes'] = '';
        }
        $rewrite = function (mixed $src) use ($ids): mixed {
            if (is_array($src) && isset($src['import']) && is_string($src['import'])) {
                return ($ids[$src['import']] ?? null) !== null ? ['asset' => (int) $ids[$src['import']]] : null;
            }

            return $src;
        };
        foreach ($this->slides as $si => &$slide) {
            $keep = [];
            foreach ($slide['els'] as $el) {
                if (in_array($el['type'] ?? null, ['image', 'video'], true) && isset($el['src']['import'])) {
                    $new = $rewrite($el['src']);
                    if ($new === null) {
                        $this->report[] = ['slide' => $si + 1, 'element' => $el['id'] ?? null, 'kind' => 'unsupported', 'message' => 'A picture could not be stored and was left out.'];

                        continue;
                    }
                    $el['src'] = $new;
                }
                if (isset($el['poster']['import'])) {
                    $new = $rewrite($el['poster']);
                    if ($new === null) {
                        unset($el['poster']);
                    } else {
                        $el['poster'] = $new;
                    }
                }
                $keep[] = $el;
            }
            $slide['els'] = $keep;
            if (is_array($slide['bg'] ?? null) && isset($slide['bg']['img']['import'])) {
                $new = $rewrite($slide['bg']['img']);
                if ($new === null) {
                    $slide['bg'] = null;
                    $this->report[] = ['slide' => $si + 1, 'element' => null, 'kind' => 'unsupported', 'message' => 'The background picture could not be stored and was left out.'];
                } else {
                    $slide['bg']['img'] = $new;
                }
            }
        }
        unset($slide);

        return $this;
    }

    /** Report lines of one kind. @return list<array{slide: ?int, element: ?string, kind: string, message: string}> */
    public function reportOf(string $kind): array
    {
        return array_values(array_filter($this->report, fn ($r) => $r['kind'] === $kind));
    }
}
