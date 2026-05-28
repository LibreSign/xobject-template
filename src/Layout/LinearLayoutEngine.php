<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Layout;

use LibreSign\XObjectTemplate\Css\InlineStyleParser;
use LibreSign\XObjectTemplate\Css\StyleMap;
use LibreSign\XObjectTemplate\Html\Node;

final readonly class LinearLayoutEngine
{
    private InlineStyleParser $styleParser;
    private LayoutStyleResolver $styleResolver;
    private StructuredLayoutRenderer $structuredLayoutRenderer;

    public function __construct(?InlineStyleParser $styleParser = null)
    {
        $this->styleParser = $styleParser ?? new InlineStyleParser();
        $this->styleResolver = new LayoutStyleResolver();
        $this->structuredLayoutRenderer = new StructuredLayoutRenderer($this->styleParser, $this->styleResolver);
    }

    /**
     * @param list<Node> $nodes
     */
    public function layout(array $nodes, float $width, float $height): LayoutResult
    {
        if ($this->requiresStructuredLayout($nodes)) {
            return $this->structuredLayoutRenderer->layout($nodes, $width, $height);
        }

        return $this->layoutLinear($nodes, $width, $height);
    }

    /**
     * @param list<Node> $nodes
     */
    private function layoutLinear(array $nodes, float $width, float $height): LayoutResult
    {
        $lines = [];
        $images = [];

        $cursorY = $height - 12.0;
        $lineHeight = 12.0;
        $imageCount = 0;

        foreach ($this->walk($nodes) as $node) {
            $style = $this->styleParser->parse($node->attributes['style'] ?? '');
            $margin = $this->parseBoxSpacing($this->styleValue($style, 'margin', '0'));
            $padding = $this->parseBoxSpacing($this->styleValue($style, 'padding', '0'));

            $cursorY -= ($margin['top'] + $padding['top']);

            $fontSize = $this->toPoints($this->styleValue($style, 'font-size', '10'));
            $lineHeight = $this->resolveLineHeight($style, $fontSize);
            $fontAlias = $this->resolveFontAlias(
                $this->styleValue($style, 'font-family', 'helvetica'),
                $this->styleValue($style, 'font-weight', 'normal'),
            );

            $boxWidth = $this->toPoints($this->styleValue($style, 'width', '0'));
            if ($boxWidth <= 0) {
                $boxWidth = max($width - $margin['left'] - $margin['right'] - $padding['left'] - $padding['right'], 0);
            }
            $leftBase = $margin['left'] + $padding['left'];
            $rightBase = $leftBase + $boxWidth;

            if ($node->tag === 'img') {
                $imgWidth = $this->toPoints($this->styleValue($style, 'width', '32'));
                $imgHeight = $this->toPoints($this->styleValue($style, 'height', '32'));
                if ($imgWidth <= 0) {
                    $imgWidth = 32.0;
                }
                if ($imgHeight <= 0) {
                    $imgHeight = 32.0;
                }

                $images[] = new LayoutImage(
                    alias: 'Im' . $imageCount,
                    x: $leftBase,
                    y: max($cursorY - $imgHeight, 0),
                    width: min($imgWidth, $width),
                    height: min($imgHeight, $height),
                    source: $node->attributes['src'] ?? '',
                );
                ++$imageCount;
                $cursorY -= ($imgHeight + 2.0 + $margin['bottom'] + $padding['bottom']);
                continue;
            }

            if ($node->tag === 'br') {
                $cursorY -= $lineHeight;
                continue;
            }

            $text = trim($node->text);
            if ($text === '') {
                continue;
            }

            $align = strtolower($this->styleValue($style, 'text-align', 'left'));
            $lineX = match ($align) {
                'center' => $leftBase + ($boxWidth / 2.0),
                'right' => max($rightBase - 8.0, 0),
                default => $leftBase + 8.0,
            };

            $lines[] = new LayoutLine(
                text: $text,
                x: $lineX,
                y: max($cursorY, 0),
                fontSize: $fontSize,
                fontAlias: $fontAlias,
                rgbColor: $this->styleValue($style, 'color', '#000000'),
            );

            $cursorY -= ($lineHeight + $margin['bottom'] + $padding['bottom']);
        }

        return new LayoutResult(lines: $lines, images: $images);
    }

    /**
     * @param list<Node> $nodes
     */
    private function requiresStructuredLayout(array $nodes): bool
    {
        foreach ($this->walk($nodes) as $node) {
            $style = $this->styleParser->parse($node->attributes['style'] ?? '');
            if ($this->containsStructuredLayoutRules($style)) {
                return true;
            }
        }

        return false;
    }

    private function containsStructuredLayoutRules(StyleMap $style): bool
    {
        if (strtolower(trim($this->styleValue($style, 'display', ''))) === 'flex') {
            return true;
        }

        if ($this->styleResolver->isAbsolutelyPositioned($style)) {
            return true;
        }

        foreach (['width', 'height', 'left', 'top', 'right', 'bottom', 'gap'] as $property) {
            if (str_contains($this->styleValue($style, $property, ''), '%')) {
                return true;
            }
        }

        $justifyContent = strtolower(trim($this->styleValue($style, 'justify-content', '')));
        if (in_array($justifyContent, ['center', 'flex-end', 'space-between'], true)) {
            return true;
        }

        $alignItems = strtolower(trim($this->styleValue($style, 'align-items', '')));

        return in_array($alignItems, ['center', 'flex-end'], true);
    }

    private function styleValue(StyleMap $style, string $property, string $default): string
    {
        return $this->styleResolver->styleValue($style, $property, $default);
    }

    /**
     * @param list<Node> $nodes
     * @return list<Node>
     */
    private function walk(array $nodes): array
    {
        $result = [];
        $stack = array_reverse($nodes);

        while ($stack !== []) {
            $node = array_pop($stack);
            $result[] = $node;

            if ($node->children === []) {
                continue;
            }

            foreach (array_reverse($node->children) as $child) {
                $stack[] = $child;
            }
        }

        return $result;
    }

    private function toPoints(string $value): float
    {
        return $this->styleResolver->toPoints($value);
    }

    private function resolveLineHeight(StyleMap $style, float $fontSize): float
    {
        return $this->styleResolver->resolveLineHeight($style, $fontSize);
    }

    /**
     * @return array{top: float, right: float, bottom: float, left: float}
     */
    private function parseBoxSpacing(string $value): array
    {
        return $this->styleResolver->parseBoxSpacing($value);
    }

    private function resolveFontAlias(string $fontFamily, string $fontWeight): string
    {
        return $this->styleResolver->resolveFontAlias($fontFamily, $fontWeight);
    }
}
