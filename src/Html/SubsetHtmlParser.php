<?php

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Html;

use DOMDocument;
use DOMElement;
use DOMNode;
use LibreSign\XObjectTemplate\Exception\UnsupportedSubsetException;

final class SubsetHtmlParser
{
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
     */
    public function parse(string $html): array
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previousUseInternalErrors = libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="utf-8" ?><body>' . $html . '</body>',
            LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseInternalErrors);

        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body instanceof DOMElement) {
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
            $tag = strtolower($node->tagName);
            if (!isset($this->allowedTags[$tag])) {
                throw new UnsupportedSubsetException(sprintf('Tag <%s> is not supported.', $tag));
            }

            $attributes = [];
            if ($node->attributes !== null) {
                foreach ($node->attributes as $attribute) {
                    $attributes[strtolower($attribute->name)] = $attribute->value;
                }
            }

            $ownStyle = $attributes['style'] ?? '';
            $effectiveStyle = $this->mergeStyle($inheritedStyle, $ownStyle);
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

    private function mergeStyle(string $inheritedStyle, string $ownStyle): string
    {
        $inheritedStyle = trim($inheritedStyle);
        $ownStyle = trim($ownStyle);

        if ($inheritedStyle === '') {
            return $ownStyle;
        }

        if ($ownStyle === '') {
            return $inheritedStyle;
        }

        return $inheritedStyle . ';' . $ownStyle;
    }
}
