<?php

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Layout;

use LibreSign\XObjectTemplate\Css\InlineStyleParser;
use LibreSign\XObjectTemplate\Html\Node;

final class LinearLayoutEngine
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
            $fontSize = $this->toPoints($style->get('font-size', '10'));
            $lineHeight = max($fontSize * 1.2, $this->toPoints($style->get('line-height', (string) ($fontSize * 1.2))));

            if ($node->tag === 'img') {
                $imgWidth = $this->toPoints($style->get('width', '32'));
                $imgHeight = $this->toPoints($style->get('height', '32'));
                $images[] = new LayoutImage(
                    alias: 'Im' . $imageCount,
                    x: 4.0,
                    y: max($cursorY - $imgHeight, 0),
                    width: min($imgWidth, $width),
                    height: min($imgHeight, $height),
                    source: $node->attributes['src'] ?? '',
                );
                ++$imageCount;
                $cursorY -= ($imgHeight + 2.0);
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
                'center' => $width / 2.0,
                'right' => max($width - 8.0, 0),
                default => 8.0,
            };

            $lines[] = new LayoutLine(
                text: $text,
                x: $x,
                y: max($cursorY, 0),
                fontSize: $fontSize,
                fontAlias: 'F1',
                rgbColor: (string) $style->get('color', '#000000'),
            );

            $cursorY -= $lineHeight;
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
}
