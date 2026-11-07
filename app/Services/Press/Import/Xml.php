<?php

namespace App\Services\Press\Import;

use DOMDocument;
use DOMElement;
use DOMNode;

/** Safe XML loading and small helpers for walking OOXML by local name. */
final class Xml
{
    /**
     * Parse an OOXML part. A document type declaration is refused outright (no OOXML part needs one, and it is where
     * entity attacks live), the network is never touched and entities are never substituted.
     */
    public static function load(string $xml): ?DOMDocument
    {
        if ($xml === '') {
            return null;
        }
        // Drop a BOM and look at the prolog: DOCTYPE and ENTITY are never legitimate here.
        $head = substr($xml, 0, 4096);
        if (stripos($head, '<!DOCTYPE') !== false || stripos($head, '<!ENTITY') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new ImportException('The file contains a document type declaration, which is not allowed.');
        }
        $prev = libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        $dom->resolveExternals = false;
        $dom->substituteEntities = false;
        $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return $ok ? $dom : null;
    }

    public static function child(?DOMNode $n, string $local): ?DOMElement
    {
        if ($n === null) {
            return null;
        }
        foreach ($n->childNodes as $c) {
            if ($c instanceof DOMElement && $c->localName === $local) {
                return $c;
            }
        }

        return null;
    }

    /** @return list<DOMElement> */
    public static function children(?DOMNode $n, ?string $local = null): array
    {
        $out = [];
        if ($n === null) {
            return $out;
        }
        foreach ($n->childNodes as $c) {
            if ($c instanceof DOMElement && ($local === null || $c->localName === $local)) {
                $out[] = $c;
            }
        }

        return $out;
    }

    /** First descendant with the local name (depth first). */
    public static function find(?DOMNode $n, string $local): ?DOMElement
    {
        if ($n === null) {
            return null;
        }
        foreach ($n->childNodes as $c) {
            if (! $c instanceof DOMElement) {
                continue;
            }
            if ($c->localName === $local) {
                return $c;
            }
            $f = self::find($c, $local);
            if ($f !== null) {
                return $f;
            }
        }

        return null;
    }

    /** @return list<DOMElement> */
    public static function findAll(?DOMNode $n, string $local, int $limit = 5000): array
    {
        $out = [];
        $walk = function (DOMNode $x) use (&$walk, &$out, $local, $limit): void {
            foreach ($x->childNodes as $c) {
                if (! $c instanceof DOMElement || count($out) >= $limit) {
                    continue;
                }
                if ($c->localName === $local) {
                    $out[] = $c;
                }
                $walk($c);
            }
        };
        if ($n !== null) {
            $walk($n);
        }

        return $out;
    }

    public static function attr(?DOMElement $e, string $name, ?string $default = null): ?string
    {
        if ($e === null || ! $e->hasAttribute($name)) {
            return $default;
        }

        return $e->getAttribute($name);
    }

    /** A namespaced attribute such as r:id (the plain id attribute of the same name is not matched). */
    public static function attrLocal(?DOMElement $e, string $local): ?string
    {
        if ($e === null) {
            return null;
        }
        foreach ($e->attributes as $a) {
            if ($a->localName === $local && $a->namespaceURI !== null && $a->namespaceURI !== '') {
                return $a->value;
            }
        }

        return null;
    }

    public static function int(?DOMElement $e, string $name, int $default = 0): int
    {
        $v = self::attr($e, $name);

        return $v !== null && is_numeric($v) ? (int) $v : $default;
    }
}
