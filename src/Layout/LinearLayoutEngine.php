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

    public function __construct(?InlineStyleParser $styleParser = null)
    {
        $this->styleParser = $styleParser ?? new InlineStyleParser();
    }

    /**
     * @param list<Node> $nodes
     */
    public function layout(array $nodes, float $width, float $height): LayoutResult
    {
        if ($this->requiresStructuredLayout($nodes)) {
            return $this->layoutStructured($nodes, $width, $height);
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
    private function layoutStructured(array $nodes, float $width, float $height): LayoutResult
    {
        $lines = [];
        $images = [];
        $imageCount = 0;

        $this->layoutStructuredNodes(
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

    private function styleValue(
        StyleMap $style,
        string $property,
        string $default,
    ): string {
        return $style->get($property, $default) ?? $default;
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
        $display = strtolower(trim($this->styleValue($style, 'display', '')));
        if ($display === 'flex') {
            return true;
        }

        if ($this->isAbsolutelyPositioned($style)) {
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

    /**
     * @param list<Node> $nodes
     * @param array{x: float, y: float, width: float, height: float} $container
     * @param list<LayoutLine> $lines
     * @param list<LayoutImage> $images
     */
    private function layoutStructuredNodes(
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

            if ($this->isAbsolutelyPositioned($style)) {
                $this->layoutStructuredAbsoluteNode(
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

            $consumedHeight += $this->layoutStructuredFlowNode(
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
    private function layoutStructuredFlowNode(
        Node $node,
        StyleMap $style,
        array $availableBox,
        float $canvasHeight,
        array &$lines,
        array &$images,
        int &$imageCount,
    ): float {
        $margin = $this->parseBoxSpacingRelative(
            $this->styleValue($style, 'margin', '0'),
            $availableBox['width'],
            $availableBox['height'],
        );

        $resolvedWidth = $this->resolveRelativeDimension(
            $this->styleValue($style, 'width', ''),
            $availableBox['width'],
        );
        if ($resolvedWidth <= 0.0) {
            $resolvedWidth = match (true) {
                $node->tag === 'img' => 32.0,
                default => max($availableBox['width'] - $margin['left'] - $margin['right'], 0.0),
            };
        }

        $resolvedHeight = $this->resolveRelativeDimension(
            $this->styleValue($style, 'height', ''),
            $availableBox['height'],
        );
        if ($resolvedHeight <= 0.0 && $node->tag === 'img') {
            $resolvedHeight = 32.0;
        }

        $box = [
            'x' => $availableBox['x'] + $margin['left'],
            'y' => $availableBox['y'] + $margin['top'],
            'width' => max($resolvedWidth, 0.0),
            'height' => max($resolvedHeight, 0.0),
        ];

        $renderedHeight = $this->renderStructuredResolvedNode(
            node: $node,
            style: $style,
            box: $box,
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
    private function layoutStructuredAbsoluteNode(
        Node $node,
        StyleMap $style,
        array $container,
        float $canvasHeight,
        array &$lines,
        array &$images,
        int &$imageCount,
    ): void {
        $margin = $this->parseBoxSpacingRelative(
            $this->styleValue($style, 'margin', '0'),
            $container['width'],
            $container['height'],
        );

        $resolvedWidth = $this->resolveRelativeDimension(
            $this->styleValue($style, 'width', ''),
            $container['width'],
        );
        if ($resolvedWidth <= 0.0) {
            $resolvedWidth = match (true) {
                $node->tag === 'img' => 32.0,
                default => max($container['width'] - $margin['left'] - $margin['right'], 0.0),
            };
        }

        $resolvedHeight = $this->resolveRelativeDimension(
            $this->styleValue($style, 'height', ''),
            $container['height'],
        );
        if ($resolvedHeight <= 0.0) {
            $resolvedHeight = match (true) {
                $node->tag === 'img' => 32.0,
                default => max($container['height'] - $margin['top'] - $margin['bottom'], 0.0),
            };
        }

        $left = $this->styleValue($style, 'left', '');
        $right = $this->styleValue($style, 'right', '');
        $top = $this->styleValue($style, 'top', '');
        $bottom = $this->styleValue($style, 'bottom', '');

        $x = $container['x'] + $margin['left'];
        if ($left !== '') {
            $x = $container['x']
                + $this->resolveRelativeDimension($left, $container['width'])
                + $margin['left'];
        } elseif ($right !== '') {
            $x = $container['x']
                + max(
                    $container['width']
                    - $resolvedWidth
                    - $this->resolveRelativeDimension($right, $container['width'])
                    - $margin['right'],
                    0.0,
                );
        }

        $y = $container['y'] + $margin['top'];
        if ($top !== '') {
            $y = $container['y']
                + $this->resolveRelativeDimension($top, $container['height'])
                + $margin['top'];
        } elseif ($bottom !== '') {
            $y = $container['y']
                + max(
                    $container['height']
                    - $resolvedHeight
                    - $this->resolveRelativeDimension($bottom, $container['height'])
                    - $margin['bottom'],
                    0.0,
                );
        }

        $this->renderStructuredResolvedNode(
            node: $node,
            style: $style,
            box: [
                'x' => $x,
                'y' => $y,
                'width' => max($resolvedWidth, 0.0),
                'height' => max($resolvedHeight, 0.0),
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
    private function renderStructuredResolvedNode(
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
            return $this->renderStructuredImage($node, $box, $canvasHeight, $images, $imageCount);
        }

        if (trim($node->text) !== '' && $node->children === []) {
            return $this->renderStructuredBlockContainer(
                node: $node,
                style: $style,
                box: $box,
                canvasHeight: $canvasHeight,
                lines: $lines,
                images: $images,
                imageCount: $imageCount,
            );
        }

        if (strtolower(trim($this->styleValue($style, 'display', ''))) === 'flex') {
            return $this->renderStructuredFlexContainer(
                node: $node,
                style: $style,
                box: $box,
                canvasHeight: $canvasHeight,
                lines: $lines,
                images: $images,
                imageCount: $imageCount,
            );
        }

        return $this->renderStructuredBlockContainer(
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
    private function renderStructuredBlockContainer(
        Node $node,
        StyleMap $style,
        array $box,
        float $canvasHeight,
        array &$lines,
        array &$images,
        int &$imageCount,
    ): float {
        ['padding' => $padding, 'contentBox' => $contentBox] = $this->resolveStructuredContentBox($style, $box);

        $consumedContentHeight = 0.0;
        if (trim($node->text) !== '') {
            $consumedContentHeight += $this->renderStructuredTextLine(
                node: $node,
                style: $style,
                box: $contentBox,
                canvasHeight: $canvasHeight,
                lines: $lines,
            );
        }

        if ($node->children !== []) {
            $childContainer = [
                'x' => $contentBox['x'],
                'y' => $contentBox['y'] + $consumedContentHeight,
                'width' => $contentBox['width'],
                'height' => $contentBox['height'] > 0.0
                    ? max($contentBox['height'] - $consumedContentHeight, 0.0)
                    : 0.0,
            ];

            $consumedContentHeight += $this->layoutStructuredNodes(
                nodes: $node->children,
                container: $childContainer,
                canvasHeight: $canvasHeight,
                lines: $lines,
                images: $images,
                imageCount: $imageCount,
            );
        }

        $autoHeight = $padding['top'] + $consumedContentHeight + $padding['bottom'];

        return $box['height'] > 0.0 ? max($box['height'], $autoHeight) : $autoHeight;
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $box
     * @param list<LayoutLine> $lines
     * @param list<LayoutImage> $images
     */
    private function renderStructuredFlexContainer(
        Node $node,
        StyleMap $style,
        array $box,
        float $canvasHeight,
        array &$lines,
        array &$images,
        int &$imageCount,
    ): float {
        ['padding' => $padding, 'contentBox' => $contentBox] = $this->resolveStructuredContentBox($style, $box);

        $direction = strtolower(trim($this->styleValue($style, 'flex-direction', 'row')));
        if ($direction !== 'column') {
            $direction = 'row';
        }

        $justifyContent = strtolower(trim($this->styleValue($style, 'justify-content', 'flex-start')));
        $alignItems = strtolower(trim($this->styleValue($style, 'align-items', 'flex-start')));
        $gap = $this->resolveRelativeDimension(
            $this->styleValue($style, 'gap', '0'),
            $direction === 'column' ? $contentBox['height'] : $contentBox['width'],
        );

        $items = [];
        foreach ($node->children as $child) {
            $childStyle = $this->styleParser->parse($child->attributes['style'] ?? '');
            if ($this->isAbsolutelyPositioned($childStyle)) {
                $this->layoutStructuredAbsoluteNode(
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
                'size' => $this->measureStructuredFlexItem($child, $childStyle, $contentBox),
            ];
        }

        if ($items === []) {
            return $box['height'] > 0.0 ? $box['height'] : ($padding['top'] + $padding['bottom']);
        }

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
        $mainAxisOffset = match ($justifyContent) {
            'center' => max(($mainContainerSize - $totalMainAxisSize) / 2.0, 0.0),
            'flex-end' => max($mainContainerSize - $totalMainAxisSize, 0.0),
            default => 0.0,
        };

        $cursor = $mainAxisOffset;
        foreach ($items as $item) {
            $itemWidth = $item['size']['width'];
            $itemHeight = $item['size']['height'];

            if ($direction === 'row') {
                $crossOffset = $this->resolveCrossAxisOffset($alignItems, $crossContainerSize, $itemHeight);
                $childBox = [
                    'x' => $contentBox['x'] + $cursor,
                    'y' => $contentBox['y'] + $crossOffset,
                    'width' => $itemWidth,
                    'height' => $itemHeight > 0.0 ? $itemHeight : $contentBox['height'],
                ];
                $cursor += $itemWidth + $gap;
            } else {
                $crossOffset = $this->resolveCrossAxisOffset($alignItems, $crossContainerSize, $itemWidth);
                $childBox = [
                    'x' => $contentBox['x'] + $crossOffset,
                    'y' => $contentBox['y'] + $cursor,
                    'width' => $itemWidth > 0.0 ? $itemWidth : $contentBox['width'],
                    'height' => $itemHeight,
                ];
                $cursor += $itemHeight + $gap;
            }

            $this->renderStructuredResolvedNode(
                node: $item['node'],
                style: $item['style'],
                box: $childBox,
                canvasHeight: $canvasHeight,
                lines: $lines,
                images: $images,
                imageCount: $imageCount,
            );
        }

        $autoHeight = $direction === 'row'
            ? $padding['top'] + $crossAxisSize + $padding['bottom']
            : $padding['top'] + $totalMainAxisSize + $padding['bottom'];

        return $box['height'] > 0.0 ? max($box['height'], $autoHeight) : $autoHeight;
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $box
     * @param list<LayoutLine> $lines
     */
    private function renderStructuredTextLine(
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

        $fontSize = $this->toPoints($this->styleValue($style, 'font-size', '10'));
        $lineHeight = $this->resolveLineHeight($style, $fontSize);
        $fontAlias = $this->resolveFontAlias(
            $this->styleValue($style, 'font-family', 'helvetica'),
            $this->styleValue($style, 'font-weight', 'normal'),
        );

        $align = strtolower($this->styleValue($style, 'text-align', 'left'));
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
            rgbColor: $this->styleValue($style, 'color', '#000000'),
        );

        return $lineHeight;
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $box
     * @param list<LayoutImage> $images
     */
    private function renderStructuredImage(
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
        $padding = $this->parseBoxSpacingRelative(
            $this->styleValue($style, 'padding', '0'),
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
     * @param array{x: float, y: float, width: float, height: float} $container
     * @return array{width: float, height: float}
     */
    private function measureStructuredFlexItem(Node $node, StyleMap $style, array $container): array
    {
        $width = $this->resolveRelativeDimension($this->styleValue($style, 'width', ''), $container['width']);
        if ($width <= 0.0) {
            $width = $node->tag === 'img' ? 32.0 : 0.0;
        }

        $height = $this->resolveRelativeDimension($this->styleValue($style, 'height', ''), $container['height']);
        if ($height <= 0.0) {
            $height = match (true) {
                $node->tag === 'img' => 32.0,
                trim($node->text) !== '' => $this->resolveLineHeight(
                    $style,
                    $this->toPoints($this->styleValue($style, 'font-size', '10')),
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

    private function resolveRelativeDimension(string $value, float $reference): float
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return 0.0;
        }

        if (str_ends_with($normalized, '%')) {
            $number = (float) preg_replace('/[^0-9.\-]/', '', $normalized);

            return $reference * ($number / 100.0);
        }

        return $this->toPoints($normalized);
    }

    /**
     * @return array{top: float, right: float, bottom: float, left: float}
     */
    private function parseBoxSpacingRelative(string $value, float $widthReference, float $heightReference): array
    {
        preg_match_all('/\S+/', $value, $matches);
        $tokens = $matches[0];

        if ($tokens === []) {
            return ['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0];
        }

        $expanded = match (count($tokens)) {
            1 => [$tokens[0], $tokens[0], $tokens[0], $tokens[0]],
            2 => [$tokens[0], $tokens[1], $tokens[0], $tokens[1]],
            3 => [$tokens[0], $tokens[1], $tokens[2], $tokens[1]],
            default => [$tokens[0], $tokens[1], $tokens[2], $tokens[3]],
        };

        return [
            'top' => $this->resolveRelativeDimension($expanded[0], $heightReference),
            'right' => $this->resolveRelativeDimension($expanded[1], $widthReference),
            'bottom' => $this->resolveRelativeDimension($expanded[2], $heightReference),
            'left' => $this->resolveRelativeDimension($expanded[3], $widthReference),
        ];
    }

    private function isAbsolutelyPositioned(StyleMap $style): bool
    {
        return strtolower(trim($this->styleValue($style, 'position', ''))) === 'absolute';
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
        StyleMap $style,
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
