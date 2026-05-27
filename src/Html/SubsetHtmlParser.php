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
        @$dom->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);

        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body instanceof DOMElement) {
            return [];
        }

        $nodes = [];
        foreach ($body->childNodes as $child) {
            $parsed = $this->parseDomNode($child);
            if ($parsed !== null) {
                $nodes[] = $parsed;
            }
        }

        return $nodes;
    }

    private function parseDomNode(DOMNode $node): ?Node
    {
        if ($node instanceof DOMElement) {
            $tag = strtolower($node->tagName);
            if (!isset($this->allowedTags[$tag])) {
                throw new UnsupportedSubsetException(sprintf('Tag <%s> is not supported in MVP subset.', $tag));
            }

            $attributes = [];
            foreach ($node->attributes as $attribute) {
                $attributes[strtolower($attribute->name)] = $attribute->value;
            }

            $children = [];
            foreach ($node->childNodes as $childNode) {
                $child = $this->parseDomNode($childNode);
                if ($child !== null) {
                    $children[] = $child;
                }
            }

            return new Node(
                tag: $tag,
                text: trim($node->textContent ?? ''),
                attributes: $attributes,
                children: $children,
            );
        }

        $text = trim($node->textContent ?? '');
        if ($text === '') {
            return null;
        }

        return new Node(tag: 'span', text: $text);
    }
}
