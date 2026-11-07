<?php

namespace App\Services\Press\Assets;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Rebuilds an SVG from an allow-list. The input is parsed, never trusted: a new document is built node by node
 * and only known elements and attributes are copied across, so nothing that was not explicitly allowed survives.
 *
 * Removed outright: script, foreignObject, animation elements, event attributes, every external reference,
 * javascript: and data: URLs, url() that does not point at a fragment of the same file, entities, DTDs,
 * processing instructions and comments.
 */
final class SvgSanitizer
{
    private const SVG_NS = 'http://www.w3.org/2000/svg';

    private const XLINK_NS = 'http://www.w3.org/1999/xlink';

    private const ELEMENTS = [
        'svg', 'g', 'defs', 'symbol', 'use', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        'text', 'tspan', 'textPath', 'title', 'desc', 'linearGradient', 'radialGradient', 'stop', 'clipPath', 'mask',
        'pattern', 'marker', 'style', 'filter', 'feBlend', 'feColorMatrix', 'feComposite', 'feFlood', 'feGaussianBlur',
        'feMerge', 'feMergeNode', 'feMorphology', 'feOffset', 'feDropShadow', 'feComponentTransfer', 'feFuncR',
        'feFuncG', 'feFuncB', 'feFuncA',
    ];

    private const TEXT_ELEMENTS = ['text', 'tspan', 'textPath', 'title', 'desc', 'style'];

    private const ATTRIBUTES = [
        'id', 'class', 'x', 'y', 'width', 'height', 'cx', 'cy', 'r', 'rx', 'ry', 'x1', 'y1', 'x2', 'y2', 'fx', 'fy', 'fr',
        'points', 'd', 'pathLength', 'transform', 'viewBox', 'preserveAspectRatio', 'fill', 'fill-opacity', 'fill-rule',
        'clip-rule', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-dasharray', 'stroke-dashoffset',
        'stroke-opacity', 'stroke-miterlimit', 'opacity', 'color', 'display', 'visibility', 'overflow', 'style',
        'gradientUnits', 'gradientTransform', 'spreadMethod', 'offset', 'stop-color', 'stop-opacity', 'patternUnits',
        'patternContentUnits', 'patternTransform', 'clipPathUnits', 'maskUnits', 'maskContentUnits', 'clip-path', 'mask',
        'filter', 'marker-start', 'marker-mid', 'marker-end', 'markerWidth', 'markerHeight', 'markerUnits', 'refX', 'refY',
        'orient', 'font-family', 'font-size', 'font-weight', 'font-style', 'font-variant', 'text-anchor', 'dominant-baseline',
        'alignment-baseline', 'baseline-shift', 'letter-spacing', 'word-spacing', 'text-decoration', 'dx', 'dy', 'rotate',
        'textLength', 'lengthAdjust', 'startOffset', 'method', 'spacing', 'role', 'aria-label', 'aria-hidden',
        'aria-labelledby', 'aria-describedby', 'stdDeviation', 'in', 'in2', 'result', 'mode', 'type', 'values', 'operator',
        'k1', 'k2', 'k3', 'k4', 'flood-color', 'flood-opacity', 'radius', 'filterUnits', 'primitiveUnits', 'tableValues',
        'slope', 'intercept', 'amplitude', 'exponent', 'color-interpolation-filters', 'xml:space', 'vector-effect',
        'shape-rendering', 'text-rendering', 'paint-order',
    ];

    /** Presentation attributes whose value may be a paint server or reference: only url(#fragment) is allowed. */
    private const URL_ATTRIBUTES = ['fill', 'stroke', 'filter', 'mask', 'clip-path', 'marker-start', 'marker-mid', 'marker-end', 'style'];

    private int $nodes = 0;

    /**
     * @return array{svg: string, width: int, height: int}
     */
    public function sanitise(string $source): array
    {
        if (strlen($source) > AssetLimits::SVG_BYTES) {
            throw new AssetRejected('SVG files can be up to '.AssetLimits::human(AssetLimits::SVG_BYTES).'.');
        }

        if (str_starts_with($source, "\xEF\xBB\xBF")) {
            $source = substr($source, 3);
        }

        if (str_contains($source, "\0") || ! mb_check_encoding($source, 'UTF-8')) {
            throw new AssetRejected('This SVG is not valid UTF-8 text.');
        }

        // A DOCTYPE with no internal subset is only the old public identifier line some editors write; drop it.
        // Anything with an internal subset or an ENTITY is how XML bombs and file reads are built: refuse.
        $source = (string) preg_replace('/<!DOCTYPE[^>\[]*>/i', '', $source);
        if (preg_match('/<!(DOCTYPE|ENTITY|ELEMENT|ATTLIST|NOTATION)/i', $source)) {
            throw new AssetRejected('This SVG contains a DTD or entity declarations, which are not allowed.');
        }

        $previous = libxml_use_internal_errors(true);
        $input = new DOMDocument;
        $input->resolveExternals = false;
        $input->substituteEntities = false;

        try {
            $loaded = $input->loadXML($source, LIBXML_NONET | LIBXML_NOBLANKS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $root = $loaded ? $input->documentElement : null;
        if (! $root instanceof DOMElement || $root->localName !== 'svg' || ! in_array($root->namespaceURI, [self::SVG_NS, null, ''], true)) {
            throw new AssetRejected('This file is not a valid SVG image.');
        }

        $this->nodes = 0;
        $output = new DOMDocument('1.0', 'UTF-8');
        $output->formatOutput = false;
        $clean = $this->copyElement($root, $output);
        $output->appendChild($clean);

        $clean->setAttribute('xmlns', self::SVG_NS);
        [$width, $height] = $this->dimensions($clean);

        if (! $clean->hasAttribute('viewBox') && ($clean->hasAttribute('width') || $clean->hasAttribute('height')) === false) {
            $clean->setAttribute('viewBox', "0 0 {$width} {$height}");
        }

        $markup = $output->saveXML($clean);
        if ($markup === false || $markup === '') {
            throw new AssetRejected('This SVG could not be processed.');
        }

        return ['svg' => '<?xml version="1.0" encoding="UTF-8"?>'."\n".$markup, 'width' => $width, 'height' => $height];
    }

    private function copyElement(DOMElement $source, DOMDocument $output): DOMElement
    {
        $this->nodes++;
        if ($this->nodes > AssetLimits::SVG_MAX_NODES) {
            throw new AssetRejected('This SVG is too complex ('.AssetLimits::SVG_MAX_NODES.' elements at most).');
        }

        $element = $output->createElementNS(self::SVG_NS, $source->localName);

        foreach (iterator_to_array($source->attributes) as $attribute) {
            $this->copyAttribute($source, $attribute, $element);
        }

        foreach ($source->childNodes as $child) {
            $this->copyChild($child, $source, $element, $output);
        }

        return $element;
    }

    private function copyChild(DOMNode $child, DOMElement $sourceParent, DOMElement $target, DOMDocument $output): void
    {
        if ($child instanceof DOMElement) {
            $namespace = $child->namespaceURI;
            if (! in_array($namespace, [self::SVG_NS, null, ''], true) || ! in_array($child->localName, self::ELEMENTS, true)) {
                return;
            }
            if ($child->localName === 'style' && ! $this->safeCss($child->textContent)) {
                return;
            }

            $copy = $this->copyElement($child, $output);

            if ($child->localName === 'use' && ! $copy->hasAttribute('href')) {
                return;
            }

            $target->appendChild($copy);

            return;
        }

        $isText = $child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE;
        if ($isText && in_array($sourceParent->localName, self::TEXT_ELEMENTS, true)) {
            $text = $child->nodeValue ?? '';
            // Control characters other than tab and newline have no place in text, and <style> must stay free of markup.
            $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text);
            $target->appendChild($output->createTextNode($text));
        }
    }

    private function copyAttribute(DOMElement $source, DOMAttr $attribute, DOMElement $target): void
    {
        $name = $attribute->localName;
        $value = $attribute->value;
        $namespace = $attribute->namespaceURI;

        if ($namespace === self::XLINK_NS && $name === 'href') {
            $this->copyReference($source, $value, $target);

            return;
        }
        if ($namespace === null || $namespace === '') {
            if ($name === 'href') {
                $this->copyReference($source, $value, $target);

                return;
            }
        } elseif ($namespace === 'http://www.w3.org/XML/1998/namespace' && $name === 'space') {
            $target->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', $value === 'preserve' ? 'preserve' : 'default');

            return;
        } else {
            return;
        }

        // xmlns declarations arrive with a namespace of their own, so they never reach here.
        if (! in_array($name, self::ATTRIBUTES, true) || str_starts_with(strtolower($name), 'on')) {
            return;
        }
        if (! $this->safeValue($name, $value)) {
            return;
        }

        $target->setAttribute($name, $value);
    }

    private function copyReference(DOMElement $source, string $value, DOMElement $target): void
    {
        $value = trim($value);

        if (! in_array($source->localName, ['use', 'linearGradient', 'radialGradient', 'pattern', 'textPath'], true)) {
            return;
        }

        if (preg_match('/^#[A-Za-z_][A-Za-z0-9_.:\-]{0,100}$/', $value) === 1) {
            $target->setAttribute('href', $value);
        }
    }

    private function safeValue(string $name, string $value): bool
    {
        if (strlen($value) > 20000) {
            return false;
        }

        // Compare against a decoded, whitespace-free, lower-case copy so that "java\tscript:" and entities do not slip by.
        $flat = strtolower((string) preg_replace('/[\s\x00-\x1F]+/u', '', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        foreach (['javascript:', 'vbscript:', 'data:', 'expression(', '<script', '&#'] as $needle) {
            if (str_contains($flat, $needle)) {
                return false;
            }
        }

        if (in_array($name, self::URL_ATTRIBUTES, true)) {
            if ($name === 'style' && ! $this->safeCss($value)) {
                return false;
            }
            if (preg_match_all('/url\(/i', $flat) !== preg_match_all('/url\([\'"]?#[a-z0-9_.:\-]+[\'"]?\)/i', $flat)) {
                return false;
            }
        } elseif (str_contains($flat, 'url(')) {
            return false;
        }

        return true;
    }

    /** CSS is allowed only in its plainest form: no at-rules, no escapes, no imports, no external urls. */
    private function safeCss(string $css): bool
    {
        $flat = strtolower((string) preg_replace('/\s+/', '', $css));

        foreach (['@', '\\', 'expression(', 'javascript:', 'vbscript:', 'data:', 'behavior', '-moz-binding', '<', '/*'] as $needle) {
            if (str_contains($flat, $needle)) {
                return false;
            }
        }

        $all = preg_match_all('/url\(/', $flat);
        $fragments = preg_match_all('/url\([\'"]?#[a-z0-9_.:\-]+[\'"]?\)/', $flat);

        return $all === $fragments;
    }

    /**
     * Intrinsic size: the viewBox when there is one, else numeric width and height, else the browser default.
     *
     * @return array{int, int}
     */
    private function dimensions(DOMElement $svg): array
    {
        $width = null;
        $height = null;

        if (preg_match('/^\s*[-\d.eE+]+[\s,]+[-\d.eE+]+[\s,]+([\d.eE+]+)[\s,]+([\d.eE+]+)\s*$/', $svg->getAttribute('viewBox'), $match) === 1) {
            $width = (float) $match[1];
            $height = (float) $match[2];
        }

        $declaredWidth = $this->length($svg->getAttribute('width'));
        $declaredHeight = $this->length($svg->getAttribute('height'));

        if ($declaredWidth !== null && $declaredHeight !== null) {
            $width = $declaredWidth;
            $height = $declaredHeight;
        } elseif ($width !== null && $height !== null) {
            if ($declaredWidth !== null && $width > 0) {
                $height = $declaredWidth * $height / $width;
                $width = $declaredWidth;
            } elseif ($declaredHeight !== null && $height > 0) {
                $width = $declaredHeight * $width / $height;
                $height = $declaredHeight;
            }
        }

        $width ??= 300.0;
        $height ??= 150.0;

        if ($width <= 0 || $height <= 0) {
            $width = 300.0;
            $height = 150.0;
        }

        return [
            max(1, min(AssetLimits::MAX_SIDE, (int) round($width))),
            max(1, min(AssetLimits::MAX_SIDE, (int) round($height))),
        ];
    }

    private function length(string $value): ?float
    {
        if (preg_match('/^\s*([0-9]*\.?[0-9]+)\s*(px)?\s*$/', $value, $match) === 1) {
            return (float) $match[1];
        }

        return null;
    }
}
