<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Html;

use DOMDocument;
use DOMElement;
use DOMNode;
use LibreSign\XObjectTemplate\Exception\UnsupportedSubsetException;

final class SubsetHtmlParser
{
    private const HTML_WRAPPER = '<?xml encoding="utf-8" ?><body>%s</body>';
    private const LIBXML_HTML_PARSE_FLAGS = 96; // LIBXML_NOERROR | LIBXML_NOWARNING
    private const INHERITABLE_STYLE_PROPERTIES = [
        'color' => true,
        'font-family' => true,
        'font-size' => true,
        'font-weight' => true,
        'hyphens' => true,
        'line-height' => true,
        'text-align' => true,
        'white-space' => true,
    ];

    /** @var array<string, true> */
    private array $allowedTags = [
        'div' => true,
        'span' => true,
        'p' => true,
        'br' => true,
        'img' => true,
    ];

    /**
     * @return list<Node>
     *
     * @throws UnsupportedSubsetException If the HTML fragment contains an element outside the supported subset.
     */
    public function parse(string $html): array
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $prevLibxmlErrors = libxml_use_internal_errors(true);
        $dom->loadHTML(
            sprintf(self::HTML_WRAPPER, $html),
            self::LIBXML_HTML_PARSE_FLAGS,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prevLibxmlErrors);

        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return [];
        }

        $nodes = [];
        foreach ($body->childNodes as $child) {
            $parsed = $this->parseDomNode($child, '');
            if ($parsed !== null) {
                $nodes[] = $parsed;
            }
        }

        return $nodes;
    }

    private function parseDomNode(DOMNode $node, string $inheritedStyle): ?Node
    {
        if ($node instanceof DOMElement) {
            return $this->parseElementNode($node, $inheritedStyle);
        }

        return $this->parseTextNode($node, $inheritedStyle);
    }

    private function parseElementNode(DOMElement $node, string $inheritedStyle): Node
    {
        $tag = $node->tagName;
        if (!isset($this->allowedTags[$tag])) {
            throw new UnsupportedSubsetException(sprintf('Tag <%s> is not supported.', $tag));
        }

        $attributes = $this->collectAttributes($node);
        $effectiveStyle = $this->mergeStyle($inheritedStyle, $attributes['style'] ?? '');
        unset($attributes['style']);
        if ($effectiveStyle !== '') {
            $attributes['style'] = $effectiveStyle;
        }

        $children = [];
        foreach ($node->childNodes as $childNode) {
            $child = $this->parseDomNode($childNode, $effectiveStyle);
            if ($child !== null) {
                $children[] = $child;
            }
        }

        return new Node(
            tag: $tag,
            text: '',
            attributes: $attributes,
            children: $children,
        );
    }

    private function parseTextNode(DOMNode $node, string $inheritedStyle): ?Node
    {
        $text = trim($node->textContent);
        if ($text === '') {
            return null;
        }

        $attributes = [];
        if ($inheritedStyle !== '') {
            $attributes['style'] = $inheritedStyle;
        }

        return new Node(tag: 'span', text: $text, attributes: $attributes);
    }

    /**
     * @return array<string, string>
     */
    private function collectAttributes(DOMElement $node): array
    {
        $attributes = [];
        $nodeAttrs = $node->attributes;
        if ($nodeAttrs !== null) {
            foreach ($nodeAttrs as $attribute) {
                $attributes[$attribute->name] = trim($attribute->value);
            }
        }

        return $attributes;
    }

    private function mergeStyle(string $inheritedStyle, string $ownStyle): string
    {
        $inheritedStyle = $this->filterInheritableStyle($inheritedStyle);

        if ($inheritedStyle === '') {
            return $ownStyle;
        }

        if ($ownStyle === '') {
            return $inheritedStyle;
        }

        return $inheritedStyle . ';' . $ownStyle;
    }

    private function filterInheritableStyle(string $style): string
    {
        if ($style === '') {
            return '';
        }

        $resolvedDeclarations = [];

        foreach (explode(';', $style) as $declaration) {
            $trimmedDeclaration = trim($declaration);
            if ($trimmedDeclaration === '') {
                continue;
            }

            $segments = explode(':', $trimmedDeclaration, 2);
            if (count($segments) !== 2) {
                continue;
            }

            $property = strtolower(trim($segments[0]));
            $value = trim($segments[1]);
            if ($value === '' || !isset(self::INHERITABLE_STYLE_PROPERTIES[$property])) {
                continue;
            }

            $resolvedDeclarations[$property] = $property . ':' . $value;
        }

        return implode(';', array_values($resolvedDeclarations));
    }
}
