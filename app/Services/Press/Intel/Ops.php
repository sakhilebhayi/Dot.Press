<?php

namespace App\Services\Press\Intel;

/**
 * Builders for the document operations of docs/programme/R40_DOCUMENT_SPEC.md section 8. The
 * intelligence services only ever build operations; the client applies them after the person agrees.
 */
final class Ops
{
    /** @param  array<string, mixed>  $patch */
    public static function elSet(string|int $slide, string $id, array $patch): array
    {
        return ['op' => 'el.set', 'slide' => $slide, 'id' => $id, 'p' => $patch];
    }

    public static function elRemove(string|int $slide, string $id): array
    {
        return ['op' => 'el.remove', 'slide' => $slide, 'id' => $id];
    }

    /** @param  array<string, mixed>  $el */
    public static function elAdd(string|int $slide, array $el, ?int $index = null): array
    {
        $op = ['op' => 'el.add', 'slide' => $slide, 'el' => $el];
        if ($index !== null) {
            $op['index'] = $index;
        }

        return $op;
    }

    /** @param  list<string>  $ids */
    public static function elOrder(string|int $slide, array $ids): array
    {
        return ['op' => 'el.order', 'slide' => $slide, 'ids' => array_values($ids)];
    }

    /** @param  array<string, mixed>  $patch */
    public static function slideSet(string|int $id, array $patch): array
    {
        return ['op' => 'slide.set', 'id' => $id] + $patch;
    }

    /** @param  array<string, mixed>  $slide */
    public static function slideAdd(string $tid, string|int|null $after, array $slide): array
    {
        return ['op' => 'slide.add', 'tid' => $tid, 'after' => $after, 'slide' => $slide];
    }

    public static function slideMove(string|int $id, string|int|null $after): array
    {
        return ['op' => 'slide.move', 'id' => $id, 'after' => $after];
    }

    public static function slideRemove(string|int $id): array
    {
        return ['op' => 'slide.remove', 'id' => $id];
    }

    public static function sectionAdd(string|int $id, string $title, string|int|null $after = null): array
    {
        $op = ['op' => 'section.add', 'id' => $id, 'title' => $title];
        if ($after !== null) {
            $op['after'] = $after;
        }

        return $op;
    }

    public static function sectionRemove(string|int $id): array
    {
        return ['op' => 'section.remove', 'id' => $id];
    }

    public static function sectionSet(string|int $id, string $title): array
    {
        return ['op' => 'section.set', 'id' => $id, 'title' => $title];
    }

    /**
     * Cap an op list at the server limit of 200 operations per request.
     *
     * @param  list<array<string, mixed>>  $ops
     * @return array{label: string, ops: list<array<string, mixed>>}
     */
    public static function fix(string $label, array $ops, int $max = 200): array
    {
        if (count($ops) > $max) {
            $label .= ' (first '.$max.' of '.count($ops).' changes)';
            $ops = array_slice($ops, 0, $max);
        }

        return ['label' => $label, 'ops' => array_values($ops)];
    }
}
