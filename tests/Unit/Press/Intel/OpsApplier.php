<?php

namespace Tests\Unit\Press\Intel;

use RuntimeException;

/**
 * A tiny test-only applier for the document operations of R40_DOCUMENT_SPEC.md section 8, enough to
 * check that the operations the intelligence services propose are valid and have the intended effect.
 * It is deliberately strict: an operation that names something missing throws.
 */
final class OpsApplier
{
    /**
     * @param  array{deck: array<string, mixed>, slides: list<array<string, mixed>>}  $doc
     * @param  list<array<string, mixed>>  $ops
     * @return array{deck: array<string, mixed>, slides: list<array<string, mixed>>}
     */
    public static function apply(array $doc, array $ops): array
    {
        foreach ($ops as $op) {
            $doc = self::one($doc, $op);
        }
        $doc['deck']['slide_order'] = array_column($doc['slides'], 'id');

        return $doc;
    }

    public static function order(array $doc): array
    {
        return array_map('strval', array_column($doc['slides'], 'id'));
    }

    private static function index(array $doc, string|int $id): int
    {
        foreach ($doc['slides'] as $i => $s) {
            if ((string) $s['id'] === (string) $id) {
                return $i;
            }
        }
        throw new RuntimeException("No slide {$id}");
    }

    private static function one(array $doc, array $op): array
    {
        $kind = $op['op'] ?? '';
        switch ($kind) {
            case 'slide.add':
                $slide = $op['slide'] + ['notes' => '', 'section_id' => null, 'hidden' => false, 'layout' => 'blank', 'bg' => null, 'transition' => null, 'els' => []];
                $slide['id'] = 'new_'.$op['tid'];
                $at = $op['after'] === null ? 0 : self::index($doc, $op['after']) + 1;
                array_splice($doc['slides'], $at, 0, [$slide]);
                break;
            case 'slide.set':
                $i = self::index($doc, $op['id']);
                foreach (['title', 'notes', 'section_id', 'hidden', 'layout', 'bg', 'transition'] as $k) {
                    if (array_key_exists($k, $op)) {
                        $doc['slides'][$i][$k] = $op[$k];
                    }
                }
                break;
            case 'slide.move':
                $i = self::index($doc, $op['id']);
                [$s] = array_splice($doc['slides'], $i, 1);
                $at = $op['after'] === null ? 0 : self::index($doc, $op['after']) + 1;
                array_splice($doc['slides'], $at, 0, [$s]);
                break;
            case 'slide.remove':
                $i = self::index($doc, $op['id']);
                array_splice($doc['slides'], $i, 1);
                break;
            case 'section.add':
                $doc['deck']['sections'][] = ['id' => $op['id'], 'title' => $op['title'] ?? ''];
                break;
            case 'section.set':
                foreach ($doc['deck']['sections'] as $k => $sec) {
                    if ((string) $sec['id'] === (string) $op['id']) {
                        $doc['deck']['sections'][$k]['title'] = $op['title'];
                    }
                }
                break;
            case 'section.remove':
                $doc['deck']['sections'] = array_values(array_filter($doc['deck']['sections'], fn ($s) => (string) $s['id'] !== (string) $op['id']));
                break;
            case 'el.set':
                $i = self::index($doc, $op['slide']);
                $found = false;
                foreach ($doc['slides'][$i]['els'] as $k => $el) {
                    if ($el['id'] === $op['id']) {
                        foreach ($op['p'] as $pk => $pv) {
                            if ($pv === null) {
                                unset($el[$pk]);
                            } else {
                                $el[$pk] = $pv;
                            }
                        }
                        $doc['slides'][$i]['els'][$k] = $el;
                        $found = true;
                    }
                }
                if (! $found) {
                    throw new RuntimeException("No element {$op['id']}");
                }
                break;
            case 'el.add':
                $i = self::index($doc, $op['slide']);
                $doc['slides'][$i]['els'][] = $op['el'];
                break;
            case 'el.remove':
                $i = self::index($doc, $op['slide']);
                $before = count($doc['slides'][$i]['els']);
                $doc['slides'][$i]['els'] = array_values(array_filter($doc['slides'][$i]['els'], fn ($e) => $e['id'] !== $op['id']));
                if (count($doc['slides'][$i]['els']) === $before) {
                    throw new RuntimeException("No element {$op['id']}");
                }
                break;
            case 'el.order':
                $i = self::index($doc, $op['slide']);
                $byId = [];
                foreach ($doc['slides'][$i]['els'] as $e) {
                    $byId[$e['id']] = $e;
                }
                if (count($op['ids']) !== count($byId) || array_diff($op['ids'], array_keys($byId)) !== []) {
                    throw new RuntimeException('el.order must list every element once');
                }
                $doc['slides'][$i]['els'] = array_map(fn ($id) => $byId[$id], $op['ids']);
                break;
            default:
                throw new RuntimeException("Unsupported op {$kind}");
        }

        return $doc;
    }
}
