<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Layout;

use LibreSign\XObjectTemplate\Css\InlineStyleParser;
use LibreSign\XObjectTemplate\Css\StyleMap;
use LibreSign\XObjectTemplate\Html\Node;

/** @internal */
final readonly class StructuredLayoutRenderer
{
    public function __construct(
        private InlineStyleParser $styleParser,
        private LayoutStyleResolver $styleResolver,
    ) {
    }

    /**
     * @param list<Node> $nodes
     */
    public function layout(array $nodes, float $width, float $height): LayoutResult
    {
        $lines = [];
        $images = [];
        $imageCount = 0;

        $this->layoutNodes(
            nodes: $nodes,
            container: [
                'x' => 0.0,
                'y' => 0.0,
                'width' => max($width, 0.0),
                'height' => max($height, 0.0),
            ],
            canvasHeight: max($height, 0.0),
            lines: $lines,
            images: $images,
            imageCount: $imageCount,
        );

        return new LayoutResult(lines: $lines, images: $images);
    }

    /**
     * @param list<Node> $nodes
     * @param array{x: float, y: float, width: float, height: float} $container
     * @param list<LayoutLine> $lines
     * @param list<LayoutImage> $images
     */
    private function layoutNodes(
        array $nodes,
        array $container,
        float $canvasHeight,
        array &$lines,
        array &$images,
        int &$imageCount,
    ): float {
        $consumedHeight = 0.0;

        foreach ($nodes as $node) {
            $style = $this->styleParser->parse($node->attributes['style'] ?? '');

            if ($this->styleResolver->isAbsolutelyPositioned($style)) {
                $this->layoutAbsoluteNode(
                    node: $node,
                    style: $style,
                    container: $container,
                    canvasHeight: $canvasHeight,
                    lines: $lines,
                    images: $images,
                    imageCount: $imageCount,
                );
                continue;
            }

            $availableBox = [
                'x' => $container['x'],
                'y' => $container['y'] + $consumedHeight,
                'width' => $container['width'],
                'height' => max($container['height'] - $consumedHeight, 0.0),
            ];

            $consumedHeight += $this->layoutFlowNode(
                node: $node,
                style: $style,
                availableBox: $availableBox,
                canvasHeight: $canvasHeight,
                lines: $lines,
                images: $images,
                imageCount: $imageCount,
            );
        }

        return $consumedHeight;
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $availableBox
     * @param list<LayoutLine> $lines
     * @param list<LayoutImage> $images
     */
    private function layoutFlowNode(
        Node $node,
        StyleMap $style,
        array $availableBox,
        float $canvasHeight,
        array &$lines,
        array &$images,
        int &$imageCount,
    ): float {
        $margin = $this->styleResolver->parseBoxSpacingRelative(
            $this->styleResolver->styleValue($style, 'margin', '0'),
            $availableBox['width'],
            $availableBox['height'],
        );

        $renderedHeight = $this->renderResolvedNode(
            node: $node,
            style: $style,
            box: [
                'x' => $availableBox['x'] + $margin['left'],
                'y' => $availableBox['y'] + $margin['top'],
                'width' => $this->resolveFlowWidth($node, $style, $availableBox, $margin),
                'height' => $this->resolveFlowHeight($node, $style, $availableBox),
            ],
            canvasHeight: $canvasHeight,
            lines: $lines,
            images: $images,
            imageCount: $imageCount,
        );

        return $margin['top'] + $renderedHeight + $margin['bottom'];
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $container
     * @param list<LayoutLine> $lines
     * @param list<LayoutImage> $images
     */
    private function layoutAbsoluteNode(
        Node $node,
        StyleMap $style,
        array $container,
        float $canvasHeight,
        array &$lines,
        array &$images,
        int &$imageCount,
    ): void {
        $margin = $this->styleResolver->parseBoxSpacingRelative(
            $this->styleResolver->styleValue($style, 'margin', '0'),
            $container['width'],
            $container['height'],
        );

        $resolvedWidth = $this->resolveAbsoluteWidth($node, $style, $container, $margin);
        $resolvedHeight = $this->resolveAbsoluteHeight($node, $style, $container, $margin);

        $this->renderResolvedNode(
            node: $node,
            style: $style,
            box: [
                'x' => $this->resolveAbsoluteX($style, $container, $resolvedWidth, $margin),
                'y' => $this->resolveAbsoluteY($style, $container, $resolvedHeight, $margin),
                'width' => $resolvedWidth,
                'height' => $resolvedHeight,
            ],
            canvasHeight: $canvasHeight,
            lines: $lines,
            images: $images,
            imageCount: $imageCount,
        );
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $box
     * @param list<LayoutLine> $lines
     * @param list<LayoutImage> $images
     */
    private function renderResolvedNode(
        Node $node,
        StyleMap $style,
        array $box,
        float $canvasHeight,
        array &$lines,
        array &$images,
        int &$imageCount,
    ): float {
        if ($node->tag === 'br') {
            return 12.0;
        }

        if ($node->tag === 'img') {
            return $this->renderImage($node, $box, $canvasHeight, $images, $imageCount);
        }

        if (trim($node->text) !== '' && $node->children === []) {
            return $this->renderBlockContainer(
                node: $node,
                style: $style,
                box: $box,
                canvasHeight: $canvasHeight,
                lines: $lines,
                images: $images,
                imageCount: $imageCount,
            );
        }

        $display = strtolower(trim($this->styleResolver->styleValue($style, 'display', '')));
        if ($display === 'flex') {
            return $this->renderFlexContainer(
                node: $node,
                style: $style,
                box: $box,
                canvasHeight: $canvasHeight,
                lines: $lines,
                images: $images,
                imageCount: $imageCount,
            );
        }

        return $this->renderBlockContainer(
            node: $node,
            style: $style,
            box: $box,
            canvasHeight: $canvasHeight,
            lines: $lines,
            images: $images,
            imageCount: $imageCount,
        );
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $box
     * @param list<LayoutLine> $lines
     * @param list<LayoutImage> $images
     */
    private function renderBlockContainer(
        Node $node,
        StyleMap $style,
        array $box,
        float $canvasHeight,
        array &$lines,
        array &$images,
        int &$imageCount,
    ): float {
        ['padding' => $padding, 'contentBox' => $contentBox] = $this->resolveStructuredContentBox($style, $box);

        $contentHeight = 0.0;
        if (trim($node->text) !== '') {
            $contentHeight += $this->renderTextLine(
                node: $node,
                style: $style,
                box: $contentBox,
                canvasHeight: $canvasHeight,
                lines: $lines,
            );
        }

        if ($node->children !== []) {
            $contentHeight += $this->layoutNodes(
                nodes: $node->children,
                container: $this->createChildContainer($contentBox, $contentHeight),
                canvasHeight: $canvasHeight,
                lines: $lines,
                images: $images,
                imageCount: $imageCount,
            );
        }

        return $this->resolveAutoContainerHeight($box['height'], $padding, $contentHeight);
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $box
     * @param list<LayoutLine> $lines
     * @param list<LayoutImage> $images
     */
    private function renderFlexContainer(
        Node $node,
        StyleMap $style,
        array $box,
        float $canvasHeight,
        array &$lines,
        array &$images,
        int &$imageCount,
    ): float {
        ['padding' => $padding, 'contentBox' => $contentBox] = $this->resolveStructuredContentBox($style, $box);

        $direction = $this->normalizeFlexDirection(
            $this->styleResolver->styleValue($style, 'flex-direction', 'row'),
        );
        $justifyContent = strtolower(trim($this->styleResolver->styleValue($style, 'justify-content', 'flex-start')));
        $alignItems = strtolower(trim($this->styleResolver->styleValue($style, 'align-items', 'flex-start')));
        $gap = $this->styleResolver->resolveRelativeDimension(
            $this->styleResolver->styleValue($style, 'gap', '0'),
            $direction === 'column' ? $contentBox['height'] : $contentBox['width'],
        );

        $items = $this->collectFlexItems(
            node: $node,
            box: $box,
            contentBox: $contentBox,
            canvasHeight: $canvasHeight,
            lines: $lines,
            images: $images,
            imageCount: $imageCount,
        );

        if ($items === []) {
            return $box['height'] > 0.0 ? $box['height'] : ($padding['top'] + $padding['bottom']);
        }

        $metrics = $this->calculateFlexMetrics($items, $direction, $justifyContent, $gap, $contentBox);
        $cursor = $metrics['mainAxisOffset'];

        foreach ($items as $item) {
            $childBox = $this->createFlexChildBox(
                item: $item,
                direction: $direction,
                alignItems: $alignItems,
                contentBox: $contentBox,
                crossContainerSize: $metrics['crossContainerSize'],
                cursor: $cursor,
            );

            $this->renderResolvedNode(
                node: $item['node'],
                style: $item['style'],
                box: $childBox,
                canvasHeight: $canvasHeight,
                lines: $lines,
                images: $images,
                imageCount: $imageCount,
            );

            $cursor += $this->advanceFlexCursor($item, $direction, $metrics['gap']);
        }

        $autoHeight = $direction === 'row'
            ? $padding['top'] + $metrics['crossAxisSize'] + $padding['bottom']
            : $padding['top'] + $metrics['totalMainAxisSize'] + $padding['bottom'];

        return $box['height'] > 0.0 ? max($box['height'], $autoHeight) : $autoHeight;
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $box
     * @param list<LayoutLine> $lines
     */
    private function renderTextLine(
        Node $node,
        StyleMap $style,
        array $box,
        float $canvasHeight,
        array &$lines,
    ): float {
        $text = trim($node->text);
        if ($text === '') {
            return 0.0;
        }

        $fontSize = $this->styleResolver->toPoints($this->styleResolver->styleValue($style, 'font-size', '10'));
        $lineHeight = $this->styleResolver->resolveLineHeight($style, $fontSize);
        $fontAlias = $this->styleResolver->resolveFontAlias(
            $this->styleResolver->styleValue($style, 'font-family', 'helvetica'),
            $this->styleResolver->styleValue($style, 'font-weight', 'normal'),
        );

        $align = strtolower($this->styleResolver->styleValue($style, 'text-align', 'left'));
        $lineX = match ($align) {
            'center' => $box['x'] + ($box['width'] / 2.0),
            'right' => max($box['x'] + $box['width'], 0.0),
            default => $box['x'],
        };

        $lines[] = new LayoutLine(
            text: $text,
            x: $lineX,
            y: max($canvasHeight - ($box['y'] + $lineHeight), 0.0),
            fontSize: $fontSize,
            fontAlias: $fontAlias,
            rgbColor: $this->styleResolver->styleValue($style, 'color', '#000000'),
        );

        return $lineHeight;
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $box
     * @param list<LayoutImage> $images
     */
    private function renderImage(
        Node $node,
        array $box,
        float $canvasHeight,
        array &$images,
        int &$imageCount,
    ): float {
        $width = $box['width'] > 0.0 ? $box['width'] : 32.0;
        $height = $box['height'] > 0.0 ? $box['height'] : 32.0;

        $images[] = new LayoutImage(
            alias: 'Im' . $imageCount,
            x: $box['x'],
            y: max($canvasHeight - ($box['y'] + $height), 0.0),
            width: $width,
            height: $height,
            source: $node->attributes['src'] ?? '',
        );
        ++$imageCount;

        return $height;
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $box
     * @return array{
     *     padding: array{top: float, right: float, bottom: float, left: float},
     *     contentBox: array{x: float, y: float, width: float, height: float}
     * }
     */
    private function resolveStructuredContentBox(StyleMap $style, array $box): array
    {
        $padding = $this->styleResolver->parseBoxSpacingRelative(
            $this->styleResolver->styleValue($style, 'padding', '0'),
            $box['width'],
            $box['height'] > 0.0 ? $box['height'] : $box['width'],
        );

        return [
            'padding' => $padding,
            'contentBox' => [
                'x' => $box['x'] + $padding['left'],
                'y' => $box['y'] + $padding['top'],
                'width' => max($box['width'] - $padding['left'] - $padding['right'], 0.0),
                'height' => max($box['height'] - $padding['top'] - $padding['bottom'], 0.0),
            ],
        ];
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $contentBox
     * @return array{x: float, y: float, width: float, height: float}
     */
    private function createChildContainer(array $contentBox, float $consumedHeight): array
    {
        return [
            'x' => $contentBox['x'],
            'y' => $contentBox['y'] + $consumedHeight,
            'width' => $contentBox['width'],
            'height' => $contentBox['height'] > 0.0
                ? max($contentBox['height'] - $consumedHeight, 0.0)
                : 0.0,
        ];
    }

    /**
     * @param array{top: float, right: float, bottom: float, left: float} $padding
     */
    private function resolveAutoContainerHeight(float $resolvedHeight, array $padding, float $contentHeight): float
    {
        $autoHeight = $padding['top'] + $contentHeight + $padding['bottom'];

        return $resolvedHeight > 0.0 ? max($resolvedHeight, $autoHeight) : $autoHeight;
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $box
     * @param array{x: float, y: float, width: float, height: float} $contentBox
     * @param list<LayoutLine> $lines
     * @param list<LayoutImage> $images
     * @return list<array{node: Node, style: StyleMap, size: array{width: float, height: float}}>
     */
    private function collectFlexItems(
        Node $node,
        array $box,
        array $contentBox,
        float $canvasHeight,
        array &$lines,
        array &$images,
        int &$imageCount,
    ): array {
        $items = [];

        foreach ($node->children as $child) {
            $childStyle = $this->styleParser->parse($child->attributes['style'] ?? '');
            if ($this->styleResolver->isAbsolutelyPositioned($childStyle)) {
                $this->layoutAbsoluteNode(
                    node: $child,
                    style: $childStyle,
                    container: $box,
                    canvasHeight: $canvasHeight,
                    lines: $lines,
                    images: $images,
                    imageCount: $imageCount,
                );
                continue;
            }

            $items[] = [
                'node' => $child,
                'style' => $childStyle,
                'size' => $this->measureFlexItem($child, $childStyle, $contentBox),
            ];
        }

        return $items;
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
    private function calculateFlexMetrics(
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

    private function resolveMainAxisOffset(string $justifyContent, float $containerSize, float $contentSize): float
    {
        return match ($justifyContent) {
            'center' => max(($containerSize - $contentSize) / 2.0, 0.0),
            'flex-end' => max($containerSize - $contentSize, 0.0),
            default => 0.0,
        };
    }

    /**
     * @param array{node: Node, style: StyleMap, size: array{width: float, height: float}} $item
     * @param array{x: float, y: float, width: float, height: float} $contentBox
     * @return array{x: float, y: float, width: float, height: float}
     */
    private function createFlexChildBox(
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
    private function advanceFlexCursor(array $item, string $direction, float $gap): float
    {
        return ($direction === 'row' ? $item['size']['width'] : $item['size']['height']) + $gap;
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $container
     * @return array{width: float, height: float}
     */
    private function measureFlexItem(Node $node, StyleMap $style, array $container): array
    {
        $width = $this->styleResolver->resolveRelativeDimension(
            $this->styleResolver->styleValue($style, 'width', ''),
            $container['width'],
        );
        if ($width <= 0.0) {
            $width = $node->tag === 'img' ? 32.0 : 0.0;
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

    private function resolveCrossAxisOffset(string $alignItems, float $containerSize, float $itemSize): float
    {
        return match ($alignItems) {
            'center' => max(($containerSize - $itemSize) / 2.0, 0.0),
            'flex-end' => max($containerSize - $itemSize, 0.0),
            default => 0.0,
        };
    }

    private function normalizeFlexDirection(string $direction): string
    {
        return strtolower(trim($direction)) === 'column' ? 'column' : 'row';
    }

    /**
     * @param array{top: float, right: float, bottom: float, left: float} $margin
     * @param array{x: float, y: float, width: float, height: float} $availableBox
     */
    private function resolveFlowWidth(Node $node, StyleMap $style, array $availableBox, array $margin): float
    {
        $resolvedWidth = $this->styleResolver->resolveRelativeDimension(
            $this->styleResolver->styleValue($style, 'width', ''),
            $availableBox['width'],
        );

        if ($resolvedWidth > 0.0) {
            return $resolvedWidth;
        }

        if ($node->tag === 'img') {
            return 32.0;
        }

        return max($availableBox['width'] - $margin['left'] - $margin['right'], 0.0);
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $availableBox
     */
    private function resolveFlowHeight(Node $node, StyleMap $style, array $availableBox): float
    {
        $resolvedHeight = $this->styleResolver->resolveRelativeDimension(
            $this->styleResolver->styleValue($style, 'height', ''),
            $availableBox['height'],
        );

        if ($resolvedHeight > 0.0) {
            return $resolvedHeight;
        }

        return $node->tag === 'img' ? 32.0 : 0.0;
    }

    /**
     * @param array{top: float, right: float, bottom: float, left: float} $margin
     * @param array{x: float, y: float, width: float, height: float} $container
     */
    private function resolveAbsoluteWidth(Node $node, StyleMap $style, array $container, array $margin): float
    {
        $resolvedWidth = $this->styleResolver->resolveRelativeDimension(
            $this->styleResolver->styleValue($style, 'width', ''),
            $container['width'],
        );

        if ($resolvedWidth > 0.0) {
            return $resolvedWidth;
        }

        if ($node->tag === 'img') {
            return 32.0;
        }

        return max($container['width'] - $margin['left'] - $margin['right'], 0.0);
    }

    /**
     * @param array{top: float, right: float, bottom: float, left: float} $margin
     * @param array{x: float, y: float, width: float, height: float} $container
     */
    private function resolveAbsoluteHeight(Node $node, StyleMap $style, array $container, array $margin): float
    {
        $resolvedHeight = $this->styleResolver->resolveRelativeDimension(
            $this->styleResolver->styleValue($style, 'height', ''),
            $container['height'],
        );

        if ($resolvedHeight > 0.0) {
            return $resolvedHeight;
        }

        if ($node->tag === 'img') {
            return 32.0;
        }

        return max($container['height'] - $margin['top'] - $margin['bottom'], 0.0);
    }

    /**
     * @param array{top: float, right: float, bottom: float, left: float} $margin
     * @param array{x: float, y: float, width: float, height: float} $container
     */
    private function resolveAbsoluteX(StyleMap $style, array $container, float $resolvedWidth, array $margin): float
    {
        $left = $this->styleResolver->styleValue($style, 'left', '');
        if ($left !== '') {
            return $container['x']
                + $this->styleResolver->resolveRelativeDimension($left, $container['width'])
                + $margin['left'];
        }

        $right = $this->styleResolver->styleValue($style, 'right', '');
        if ($right === '') {
            return $container['x'] + $margin['left'];
        }

        return $container['x']
            + max(
                $container['width']
                - $resolvedWidth
                - $this->styleResolver->resolveRelativeDimension($right, $container['width'])
                - $margin['right'],
                0.0,
            );
    }

    /**
     * @param array{top: float, right: float, bottom: float, left: float} $margin
     * @param array{x: float, y: float, width: float, height: float} $container
     */
    private function resolveAbsoluteY(StyleMap $style, array $container, float $resolvedHeight, array $margin): float
    {
        $top = $this->styleResolver->styleValue($style, 'top', '');
        if ($top !== '') {
            return $container['y']
                + $this->styleResolver->resolveRelativeDimension($top, $container['height'])
                + $margin['top'];
        }

        $bottom = $this->styleResolver->styleValue($style, 'bottom', '');
        if ($bottom === '') {
            return $container['y'] + $margin['top'];
        }

        return $container['y']
            + max(
                $container['height']
                - $resolvedHeight
                - $this->styleResolver->resolveRelativeDimension($bottom, $container['height'])
                - $margin['bottom'],
                0.0,
            );
    }
}
