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
    private StructuredBoxResolver $boxResolver;
    private StructuredFlexLayoutPlanner $flexPlanner;

    public function __construct(
        private InlineStyleParser $styleParser,
        private LayoutStyleResolver $styleResolver,
    ) {
        $this->boxResolver = new StructuredBoxResolver($styleResolver);
        $this->flexPlanner = new StructuredFlexLayoutPlanner($styleResolver);
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
                $this->layoutAbsoluteNode($node, $style, $container, $canvasHeight, $lines, $images, $imageCount);
                continue;
            }

            $availableBox = [
                'x' => $container['x'],
                'y' => $container['y'] + $consumedHeight,
                'width' => $container['width'],
                'height' => max($container['height'] - $consumedHeight, 0.0),
            ];

            $consumedHeight += $this->layoutFlowNode(
                $node,
                $style,
                $availableBox,
                $canvasHeight,
                $lines,
                $images,
                $imageCount,
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
        ['margin' => $margin, 'box' => $box] = $this->boxResolver->resolveFlowPlacement($node, $style, $availableBox);

        $renderedHeight = $this->renderResolvedNode(
            $node,
            $style,
            $box,
            $canvasHeight,
            $lines,
            $images,
            $imageCount,
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
        $this->renderResolvedNode(
            $node,
            $style,
            $this->boxResolver->resolveAbsoluteBox($node, $style, $container),
            $canvasHeight,
            $lines,
            $images,
            $imageCount,
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
            return $this->renderBlockContainer($node, $style, $box, $canvasHeight, $lines, $images, $imageCount);
        }

        if (strtolower(trim($this->styleResolver->styleValue($style, 'display', ''))) === 'flex') {
            return $this->renderFlexContainer($node, $style, $box, $canvasHeight, $lines, $images, $imageCount);
        }

        return $this->renderBlockContainer($node, $style, $box, $canvasHeight, $lines, $images, $imageCount);
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
        ['padding' => $padding, 'contentBox' => $contentBox] = $this->boxResolver->resolveContentBox($style, $box);

        $contentHeight = 0.0;
        if (trim($node->text) !== '') {
            $contentHeight += $this->renderTextLine($node, $style, $contentBox, $canvasHeight, $lines);
        }

        if ($node->children !== []) {
            $contentHeight += $this->layoutNodes(
                $node->children,
                $this->boxResolver->createChildContainer($contentBox, $contentHeight),
                $canvasHeight,
                $lines,
                $images,
                $imageCount,
            );
        }

        return $this->boxResolver->resolveAutoContainerHeight($box['height'], $padding, $contentHeight);
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
        ['padding' => $padding, 'contentBox' => $contentBox] = $this->boxResolver->resolveContentBox($style, $box);

        $direction = $this->flexPlanner->normalizeDirection(
            $this->styleResolver->styleValue($style, 'flex-direction', 'row'),
        );
        $justifyContent = strtolower(trim($this->styleResolver->styleValue($style, 'justify-content', 'flex-start')));
        $alignItems = strtolower(trim($this->styleResolver->styleValue($style, 'align-items', 'flex-start')));
        $gap = $this->flexPlanner->resolveGap($style, $direction, $contentBox);

        $items = $this->collectFlexItems(
            $node,
            $box,
            $contentBox,
            $canvasHeight,
            $lines,
            $images,
            $imageCount,
        );

        if ($items === []) {
            return $box['height'] > 0.0 ? $box['height'] : ($padding['top'] + $padding['bottom']);
        }

        $metrics = $this->flexPlanner->calculateMetrics($items, $direction, $justifyContent, $gap, $contentBox);
        $cursor = $metrics['mainAxisOffset'];

        foreach ($items as $item) {
            $this->renderResolvedNode(
                $item['node'],
                $item['style'],
                $this->flexPlanner->createChildBox(
                    $item,
                    $direction,
                    $alignItems,
                    $contentBox,
                    $metrics['crossContainerSize'],
                    $cursor,
                ),
                $canvasHeight,
                $lines,
                $images,
                $imageCount,
            );

            $cursor += $this->flexPlanner->advanceCursor($item, $direction, $metrics['gap']);
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
                $this->layoutAbsoluteNode($child, $childStyle, $box, $canvasHeight, $lines, $images, $imageCount);
                continue;
            }

            $items[] = [
                'node' => $child,
                'style' => $childStyle,
                'size' => $this->flexPlanner->measureItem($child, $childStyle, $contentBox),
            ];
        }

        return $items;
    }
}
