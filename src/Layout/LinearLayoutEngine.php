<?php

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Layout;

use LibreSign\XObjectTemplate\Css\InlineStyleParser;
use LibreSign\XObjectTemplate\Html\Node;

final readonly class LinearLayoutEngine
{
    public function __construct(private InlineStyleParser $styleParser = new InlineStyleParser())
    {
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
            $margin = $this->parseBoxSpacing((string) $style->get('margin', '0'));
            $padding = $this->parseBoxSpacing((string) $style->get('padding', '0'));

            $cursorY -= ($margin['top'] + $padding['top']);

            $fontSize = $this->toPoints((string) $style->get('font-size', '10'));
            $lineHeight = max(
                $fontSize * 1.2,
                $this->toPoints((string) $style->get('line-height', (string) ($fontSize * 1.2))),
            );
            $fontAlias = $this->resolveFontAlias(
                (string) $style->get('font-family', 'helvetica'),
                (string) $style->get('font-weight', 'normal'),
            );

            $boxWidth = $this->toPoints((string) $style->get('width', '0'));
            if ($boxWidth <= 0) {
                $boxWidth = max($width - $margin['left'] - $margin['right'] - $padding['left'] - $padding['right'], 0);
            }
            $leftBase = $margin['left'] + $padding['left'];
            $rightBase = $leftBase + $boxWidth;

            if ($node->tag === 'img') {
                $imgWidth = $this->toPoints((string) $style->get('width', '32'));
                $imgHeight = $this->toPoints((string) $style->get('height', '32'));
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

            $align = strtolower((string) $style->get('text-align', 'left'));
            $x = match ($align) {
                'center' => $leftBase + ($boxWidth / 2.0),
                'right' => max($rightBase - 8.0, 0),
                default => $leftBase + 8.0,
            };

            $lines[] = new LayoutLine(
                text: $text,
                x: $x,
                y: max($cursorY, 0),
                fontSize: $fontSize,
                fontAlias: $fontAlias,
                rgbColor: (string) $style->get('color', '#000000'),
            );

            $cursorY -= ($lineHeight + $margin['bottom'] + $padding['bottom']);
        }

        return new LayoutResult(lines: $lines, images: $images);
    }

    /**
     * @param list<Node> $nodes
     * @return list<Node>
     */
    private function walk(array $nodes): array
    {
        $result = [];
        foreach ($nodes as $node) {
            $result[] = $node;
            if ($node->children !== []) {
                $result = [...$result, ...$this->walk($node->children)];
            }
        }

        return $result;
    }

    private function toPoints(string $value): float
    {
        $normalized = trim(strtolower($value));
        if ($normalized === '') {
            return 0.0;
        }

        $number = (float) preg_replace('/[^0-9.\-]/', '', $normalized);
        if (str_ends_with($normalized, 'px')) {
            return $number * 0.75;
        }

        return $number;
    }

    /**
     * @return array{top: float, right: float, bottom: float, left: float}
     */
    private function parseBoxSpacing(string $value): array
    {
        $tokens = preg_split('/\s+/', trim($value));
        $tokens = array_values(array_filter($tokens ?: [], static fn (string $token): bool => $token !== ''));

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
        $primary = strtolower(trim(explode(',', $fontFamily)[0], " \t\n\r\0\x0B'\""));
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
        $normalized = strtolower(trim($fontWeight));
        if ($normalized === 'bold' || $normalized === 'bolder') {
            return true;
        }

        if (is_numeric($normalized)) {
            return (int) $normalized >= 600;
        }

        return false;
    }
}
