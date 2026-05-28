<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Layout;

use LibreSign\XObjectTemplate\Css\StyleMap;
use LibreSign\XObjectTemplate\Html\Node;
use LibreSign\XObjectTemplate\Pdf\StandardFontMetrics;

/** @internal */
final readonly class StructuredFlexLayoutPlanner
{
    public function __construct(
        private LayoutStyleResolver $styleResolver,
        private StandardFontMetrics $fontMetrics = new StandardFontMetrics(),
    ) {
    }

    public function normalizeDirection(string $direction): string
    {
        return strtolower(trim($direction)) === 'column' ? 'column' : 'row';
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $contentBox
     */
    public function resolveGap(StyleMap $style, string $direction, array $contentBox): float
    {
        return $this->styleResolver->resolveRelativeDimension(
            $this->styleResolver->styleValue($style, 'gap', '0'),
            $direction === 'column' ? $contentBox['height'] : $contentBox['width'],
        );
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $container
     * @return array{width: float, height: float}
     */
    public function measureItem(Node $node, StyleMap $style, array $container): array
    {
        $width = $this->styleResolver->resolveRelativeDimension(
            $this->styleResolver->styleValue($style, 'width', ''),
            $container['width'],
        );
        if ($width <= 0.0) {
            $width = match (true) {
                $node->tag === 'img' => 32.0,
                trim($node->text) !== '' => $this->fontMetrics->measureString(
                    $this->styleResolver->resolveFontAlias(
                        $this->styleResolver->styleValue($style, 'font-family', 'helvetica'),
                        $this->styleResolver->styleValue($style, 'font-weight', 'normal'),
                    ),
                    $this->styleResolver->toPoints($this->styleResolver->styleValue($style, 'font-size', '10')),
                    trim($node->text),
                ),
                default => 0.0,
            };
        }

        $height = $this->styleResolver->resolveRelativeDimension(
            $this->styleResolver->styleValue($style, 'height', ''),
            $container['height'],
        );
        if ($height <= 0.0) {
            $height = match (true) {
                $node->tag === 'img' => 32.0,
                trim($node->text) !== '' => $this->styleResolver->resolveLineHeight(
                    $style,
                    $this->styleResolver->toPoints($this->styleResolver->styleValue($style, 'font-size', '10')),
                ),
                default => max($container['height'], 0.0),
            };
        }

        return [
            'width' => max($width, 0.0),
            'height' => max($height, 0.0),
        ];
    }

    /**
     * @param list<array{node: Node, style: StyleMap, size: array{width: float, height: float}}> $items
     * @param array{x: float, y: float, width: float, height: float} $contentBox
     * @return array{
     *     gap: float,
     *     mainAxisOffset: float,
     *     totalMainAxisSize: float,
     *     crossAxisSize: float,
     *     crossContainerSize: float
     * }
     */
    public function calculateMetrics(
        array $items,
        string $direction,
        string $justifyContent,
        float $gap,
        array $contentBox,
    ): array {
        $mainAxisSize = 0.0;
        $crossAxisSize = 0.0;
        foreach ($items as $item) {
            $mainAxisSize += $direction === 'row' ? $item['size']['width'] : $item['size']['height'];
            $crossAxisSize = max(
                $crossAxisSize,
                $direction === 'row' ? $item['size']['height'] : $item['size']['width'],
            );
        }

        $mainContainerSize = $direction === 'row' ? $contentBox['width'] : $contentBox['height'];
        $crossContainerSize = $direction === 'row' ? $contentBox['height'] : $contentBox['width'];

        if ($justifyContent === 'space-between' && count($items) > 1) {
            $gap = max($gap, ($mainContainerSize - $mainAxisSize) / (count($items) - 1));
        }

        $totalMainAxisSize = $mainAxisSize + ($gap * max(count($items) - 1, 0));

        return [
            'gap' => $gap,
            'mainAxisOffset' => $this->resolveMainAxisOffset($justifyContent, $mainContainerSize, $totalMainAxisSize),
            'totalMainAxisSize' => $totalMainAxisSize,
            'crossAxisSize' => $crossAxisSize,
            'crossContainerSize' => $crossContainerSize,
        ];
    }

    /**
     * @param array{node: Node, style: StyleMap, size: array{width: float, height: float}} $item
     * @param array{x: float, y: float, width: float, height: float} $contentBox
     * @return array{x: float, y: float, width: float, height: float}
     */
    public function createChildBox(
        array $item,
        string $direction,
        string $alignItems,
        array $contentBox,
        float $crossContainerSize,
        float $cursor,
    ): array {
        if ($direction === 'row') {
            return [
                'x' => $contentBox['x'] + $cursor,
                'y' => $contentBox['y']
                    + $this->resolveCrossAxisOffset($alignItems, $crossContainerSize, $item['size']['height']),
                'width' => $item['size']['width'],
                'height' => $item['size']['height'] > 0.0 ? $item['size']['height'] : $contentBox['height'],
            ];
        }

        return [
            'x' => $contentBox['x']
                + $this->resolveCrossAxisOffset($alignItems, $crossContainerSize, $item['size']['width']),
            'y' => $contentBox['y'] + $cursor,
            'width' => $item['size']['width'] > 0.0 ? $item['size']['width'] : $contentBox['width'],
            'height' => $item['size']['height'],
        ];
    }

    /**
     * @param array{node: Node, style: StyleMap, size: array{width: float, height: float}} $item
     */
    public function advanceCursor(array $item, string $direction, float $gap): float
    {
        return ($direction === 'row' ? $item['size']['width'] : $item['size']['height']) + $gap;
    }

    private function resolveMainAxisOffset(string $justifyContent, float $containerSize, float $contentSize): float
    {
        return match ($justifyContent) {
            'center' => max(($containerSize - $contentSize) / 2.0, 0.0),
            'flex-end' => max($containerSize - $contentSize, 0.0),
            default => 0.0,
        };
    }

    private function resolveCrossAxisOffset(string $alignItems, float $containerSize, float $itemSize): float
    {
        return match ($alignItems) {
            'center' => max(($containerSize - $itemSize) / 2.0, 0.0),
            'flex-end' => max($containerSize - $itemSize, 0.0),
            default => 0.0,
        };
    }
}
