<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Layout;

use LibreSign\XObjectTemplate\Css\StyleMap;
use LibreSign\XObjectTemplate\Pdf\StandardFontMetrics;

final readonly class TextBoxLayouter
{
    private TextLineBreaker $lineBreaker;
    private TextOverflowTruncator $overflowTruncator;

    public function __construct(
        private LayoutStyleResolver $styleResolver,
        private StandardFontMetrics $fontMetrics,
    ) {
        $this->lineBreaker = new TextLineBreaker($fontMetrics);
        $this->overflowTruncator = new TextOverflowTruncator($fontMetrics);
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $box
     * @param array{x: float, y: float, width: float, height: float}|null $clipBox
     * @return array{lines: list<LayoutLine>, consumedHeight: float, truncated: bool}
     */
    public function layout(
        string $text,
        StyleMap $style,
        array $box,
        float $canvasHeight,
        ?array $clipBox = null,
    ): array {
        $text = trim($text);
        if ($text === '') {
            return ['lines' => [], 'consumedHeight' => 0.0, 'truncated' => false];
        }

        $settings = $this->resolveSettings($style);
        $lines = $this->lineBreaker->wrap(
            $text,
            $box['width'],
            $settings['fontAlias'],
            $settings['fontSize'],
            $settings['hyphens'],
            $settings['whiteSpace'],
        );
        ['lines' => $lines, 'truncated' => $truncated, 'clipBox' => $effectiveClipBox] =
            $this->applyOverflowConstraints($lines, $box, $clipBox, $settings);

        return $this->buildLayoutResult(
            $lines,
            $box,
            $canvasHeight,
            $settings,
            $truncated,
            $effectiveClipBox,
        );
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $box
     * @return array{
     *     fontSize: float,
     *     lineHeight: float,
     *     fontAlias: string,
     *     align: string,
     *     overflow: string,
     *     textOverflow: string,
     *     hyphens: string,
     *     whiteSpace: string,
     *     color: string
     * }
     */
    private function resolveSettings(StyleMap $style): array
    {
        $fontSize = $this->styleResolver->toPoints($this->styleResolver->styleValue($style, 'font-size', '10'));

        return [
            'fontSize' => $fontSize,
            'lineHeight' => $this->styleResolver->resolveLineHeight($style, $fontSize),
            'fontAlias' => $this->styleResolver->resolveFontAlias(
                $this->styleResolver->styleValue($style, 'font-family', 'helvetica'),
                $this->styleResolver->styleValue($style, 'font-weight', 'normal'),
            ),
            'align' => strtolower(trim($this->styleResolver->styleValue($style, 'text-align', 'left'))),
            'overflow' => strtolower(trim($this->styleResolver->styleValue($style, 'overflow', 'visible'))),
            'textOverflow' => strtolower(trim($this->styleResolver->styleValue($style, 'text-overflow', 'clip'))),
            'hyphens' => strtolower(trim($this->styleResolver->styleValue($style, 'hyphens', 'none'))),
            'whiteSpace' => strtolower(trim($this->styleResolver->styleValue($style, 'white-space', 'normal'))),
            'color' => $this->styleResolver->styleValue($style, 'color', '#000000'),
        ];
    }

    /**
     * @param list<string> $lines
     * @param array{x: float, y: float, width: float, height: float} $box
     * @param array{x: float, y: float, width: float, height: float}|null $clipBox
     * @param array{
     *     fontSize: float,
     *     lineHeight: float,
     *     fontAlias: string,
     *     align: string,
     *     overflow: string,
     *     textOverflow: string,
     *     hyphens: string,
     *     whiteSpace: string,
     *     color: string
     * } $settings
     * @return array{
     *     lines: list<string>,
     *     truncated: bool,
     *     clipBox: array{x: float, y: float, width: float, height: float}|null
     * }
     */
    private function applyOverflowConstraints(
        array $lines,
        array $box,
        ?array $clipBox,
        array $settings,
    ): array {
        if ($settings['overflow'] !== 'hidden' || $box['height'] <= 0.0) {
            return ['lines' => $lines, 'truncated' => false, 'clipBox' => $clipBox];
        }

        $effectiveClipBox = $clipBox ?? $box;
        $maxVisibleLines = $this->resolveMaxVisibleLines($box['height'], $settings['lineHeight']);
        if ($maxVisibleLines === 0) {
            return ['lines' => [], 'truncated' => true, 'clipBox' => $effectiveClipBox];
        }

        $truncated = false;
        if (count($lines) > $maxVisibleLines) {
            $truncated = true;
            $lines = array_slice($lines, 0, $maxVisibleLines);
            if ($settings['textOverflow'] === 'ellipsis' && $lines !== []) {
                $lastIndex = count($lines) - 1;
                $lines[$lastIndex] = $this->overflowTruncator->forceEllipsis(
                    $lines[$lastIndex],
                    $box['width'],
                    $settings['fontAlias'],
                    $settings['fontSize'],
                );
            }
        }

        if ($lines !== [] && $this->shouldApplyEllipsis($lines, $truncated, $box['width'], $settings)) {
            $lastIndex = count($lines) - 1;
            $lines[$lastIndex] = $this->overflowTruncator->truncateWithEllipsis(
                $lines[$lastIndex],
                $box['width'],
                $settings['fontAlias'],
                $settings['fontSize'],
            );
            $truncated = true;
        }

        return ['lines' => $lines, 'truncated' => $truncated, 'clipBox' => $effectiveClipBox];
    }

    /**
     * @param list<string> $lines
     * @param array{
     *     fontSize: float,
     *     lineHeight: float,
     *     fontAlias: string,
     *     align: string,
     *     overflow: string,
     *     textOverflow: string,
     *     hyphens: string,
     *     whiteSpace: string,
     *     color: string
     * } $settings
     */
    private function shouldApplyEllipsis(
        array $lines,
        bool $truncated,
        float $boxWidth,
        array $settings,
    ): bool {
        if ($settings['textOverflow'] !== 'ellipsis' || $lines === []) {
            return false;
        }

        if ($truncated) {
            return true;
        }

        $lastLine = $lines[count($lines) - 1];

        return $this->fontMetrics->measureString($settings['fontAlias'], $settings['fontSize'], $lastLine) > $boxWidth;
    }

    /**
     * @param list<string> $lines
     * @param array{x: float, y: float, width: float, height: float} $box
     * @param array{
     *     fontSize: float,
     *     lineHeight: float,
     *     fontAlias: string,
     *     align: string,
     *     overflow: string,
     *     textOverflow: string,
     *     hyphens: string,
     *     whiteSpace: string,
     *     color: string
     * } $settings
     * @param array{x: float, y: float, width: float, height: float}|null $clipBox
     * @return array{lines: list<LayoutLine>, consumedHeight: float, truncated: bool}
     */
    private function buildLayoutResult(
        array $lines,
        array $box,
        float $canvasHeight,
        array $settings,
        bool $truncated,
        ?array $clipBox,
    ): array {
        $pdfClipBox = $this->toPdfClipBox($clipBox, $canvasHeight);
        $layoutLines = [];
        $lineCount = count($lines);

        foreach ($lines as $index => $lineText) {
            $layoutLines[] = $this->buildLayoutLine(
                $lineText,
                $index,
                $lineCount,
                $box,
                $canvasHeight,
                $settings,
                $pdfClipBox,
            );
        }

        return [
            'lines' => $layoutLines,
            'consumedHeight' => $lineCount * $settings['lineHeight'],
            'truncated' => $truncated,
        ];
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $box
     * @param array{
     *     fontSize: float,
     *     lineHeight: float,
     *     fontAlias: string,
     *     align: string,
     *     overflow: string,
     *     textOverflow: string,
     *     hyphens: string,
     *     whiteSpace: string,
     *     color: string
     * } $settings
     * @param array{x: float, y: float, width: float, height: float}|null $clipBox
     */
    private function buildLayoutLine(
        string $lineText,
        int $index,
        int $lineCount,
        array $box,
        float $canvasHeight,
        array $settings,
        ?array $clipBox,
    ): LayoutLine {
        $lineWidth = $this->fontMetrics->measureString($settings['fontAlias'], $settings['fontSize'], $lineText);

        return new LayoutLine(
            text: $lineText,
            x: $this->resolveLineX($settings['align'], $box, $lineWidth),
            y: max($canvasHeight - ($box['y'] + (($index + 1) * $settings['lineHeight'])), 0.0),
            fontSize: $settings['fontSize'],
            fontAlias: $settings['fontAlias'],
            rgbColor: $settings['color'],
            wordSpacing: $this->resolveWordSpacing(
                $settings['align'],
                $index,
                $lineCount,
                $lineText,
                $lineWidth,
                $box['width'],
            ),
            clipBox: $clipBox,
        );
    }

    private function resolveMaxVisibleLines(float $boxHeight, float $lineHeight): int
    {
        return max((int) ceil($boxHeight / max($lineHeight, 0.0001)), 0);
    }

    private function resolveWordSpacing(
        string $align,
        int $index,
        int $lineCount,
        string $lineText,
        float $lineWidth,
        float $boxWidth,
    ): float {
        if ($align !== 'justify' || $index >= ($lineCount - 1)) {
            return 0.0;
        }

        return $this->calculateWordSpacing($lineText, $lineWidth, $boxWidth);
    }

    /**
     * @param array{x: float, y: float, width: float, height: float}|null $clipBox
     * @return array{x: float, y: float, width: float, height: float}|null
     */
    private function toPdfClipBox(?array $clipBox, float $canvasHeight): ?array
    {
        if ($clipBox === null) {
            return null;
        }

        return [
            'x' => $clipBox['x'],
            'y' => max($canvasHeight - ($clipBox['y'] + $clipBox['height']), 0.0),
            'width' => $clipBox['width'],
            'height' => $clipBox['height'],
        ];
    }

    private function calculateWordSpacing(string $text, float $lineWidth, float $boxWidth): float
    {
        $spaceCount = substr_count($text, ' ');
        if ($spaceCount === 0 || $boxWidth <= $lineWidth) {
            return 0.0;
        }

        return ($boxWidth - $lineWidth) / $spaceCount;
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $box
     */
    private function resolveLineX(string $align, array $box, float $lineWidth): float
    {
        return match ($align) {
            'center' => $box['x'] + max(($box['width'] - $lineWidth) / 2.0, 0.0),
            'right' => $box['x'] + max($box['width'] - $lineWidth, 0.0),
            default => $box['x'],
        };
    }
}
