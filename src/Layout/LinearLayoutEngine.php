<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Layout;

use LibreSign\XObjectTemplate\Css\InlineStyleParser;
use LibreSign\XObjectTemplate\Html\Node;

final readonly class LinearLayoutEngine
{
    private InlineStyleParser $styleParser;

    public function __construct(?InlineStyleParser $styleParser = null)
    {
        $this->styleParser = $styleParser ?? new InlineStyleParser();
    }

    /**
     * @param list<Node> $nodes
     */
    public function layout(array $nodes, float $width, float $height): LayoutResult
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

    private function styleValue(
        \LibreSign\XObjectTemplate\Css\StyleMap $style,
        string $property,
        string $default,
    ): string {
        return $style->get($property, $default) ?? $default;
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
        $normalized = strtolower($value);
        $number = (float) preg_replace('/[^0-9.\-]/', '', $normalized);
        if (str_ends_with($normalized, 'px')) {
            return $number * 0.75;
        }

        return $number;
    }

    private function resolveLineHeight(
        \LibreSign\XObjectTemplate\Css\StyleMap $style,
        float $fontSize,
    ): float {
        $defaultLineHeight = $fontSize * 1.2;
        $configuredLineHeight = $this->styleValue($style, 'line-height', '');

        if ($configuredLineHeight === '') {
            return $defaultLineHeight;
        }

        return max($defaultLineHeight, $this->toPoints($configuredLineHeight));
    }

    /**
     * @return array{top: float, right: float, bottom: float, left: float}
     */
    private function parseBoxSpacing(string $value): array
    {
        preg_match_all('/\S+/', $value, $matches);
        $tokens = $matches[0];

        if ($tokens === []) {
            return ['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0];
        }

        $points = array_map(fn (string $token): float => $this->toPoints($token), $tokens);
        $count = count($points);

        if ($count === 1) {
            return ['top' => $points[0], 'right' => $points[0], 'bottom' => $points[0], 'left' => $points[0]];
        }

        if ($count === 2) {
            return ['top' => $points[0], 'right' => $points[1], 'bottom' => $points[0], 'left' => $points[1]];
        }

        if ($count === 3) {
            return ['top' => $points[0], 'right' => $points[1], 'bottom' => $points[2], 'left' => $points[1]];
        }

        return ['top' => $points[0], 'right' => $points[1], 'bottom' => $points[2], 'left' => $points[3]];
    }

    private function resolveFontAlias(string $fontFamily, string $fontWeight): string
    {
        $primary = strtolower(explode(',', $fontFamily)[0]);
        $isBold = $this->isBoldWeight($fontWeight);

        if (str_contains($primary, 'times')) {
            return $isBold ? 'F4' : 'F3';
        }

        if (str_contains($primary, 'courier')) {
            return $isBold ? 'F6' : 'F5';
        }

        return $isBold ? 'F2' : 'F1';
    }

    private function isBoldWeight(string $fontWeight): bool
    {
        $normalized = strtolower($fontWeight);
        if ($normalized === 'bold' || $normalized === 'bolder') {
            return true;
        }

        if (is_numeric($normalized)) {
            return $normalized >= 600;
        }

        return false;
    }
}
