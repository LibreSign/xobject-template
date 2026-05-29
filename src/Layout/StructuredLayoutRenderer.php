<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Layout;

use LibreSign\XObjectTemplate\Css\InlineStyleParser;
use LibreSign\XObjectTemplate\Css\StyleMap;
use LibreSign\XObjectTemplate\Pdf\StandardFontMetrics;

/** @internal */
final readonly class StructuredLayoutRenderer
{
    private StructuredBoxResolver $boxResolver;
    private StructuredFlexLayoutPlanner $flexPlanner;
    private TextBoxLayouter $textLayouter;

    public function __construct(
        private InlineStyleParser $styleParser,
        private LayoutStyleResolver $styleResolver,
    ) {
        $this->boxResolver = new StructuredBoxResolver($styleResolver);
        $this->flexPlanner = new StructuredFlexLayoutPlanner($styleResolver);
        $this->textLayouter = new TextBoxLayouter($styleResolver, new StandardFontMetrics());
    }

    /**
     * @param list<\LibreSign\XObjectTemplate\Html\Node> $nodes
     */
    public function layout(array $nodes, float $width, float $height): LayoutResult
    {
        $lines = [];
        $images = [];
        $decorations = [];
        $imageCount = 0;
        $zero = $this->styleResolver->toPoints('0');

        $this->layoutNodes(
            nodes: $nodes,
            container: [
                'x' => 0.0,
                'y' => 0.0,
                'width' => max($width, $zero),
                'height' => max($height, $zero),
            ],
            canvasHeight: max($height, $zero),
            lines: $lines,
            images: $images,
            decorations: $decorations,
            imageCount: $imageCount,
            activeClipBox: null,
        );

        return new LayoutResult(lines: $lines, images: $images, decorations: $decorations);
    }

    /**
     * @param list<\LibreSign\XObjectTemplate\Html\Node> $nodes
     * @param array{x: float, y: float, width: float, height: float} $container
     * @param list<LayoutLine> $lines
     * @param list<LayoutImage> $images
     * @param list<LayoutDecoration> $decorations
     * @param array{x: float, y: float, width: float, height: float}|null $activeClipBox
     */
    private function layoutNodes(
        array $nodes,
        array $container,
        float $canvasHeight,
        array &$lines,
        array &$images,
        array &$decorations,
        int &$imageCount,
        ?array $activeClipBox,
    ): float {
        $consumedHeight = 0.0;
        $zero = $this->styleResolver->toPoints('0');

        foreach ($nodes as $node) {
            $style = $this->styleParser->parse($node->attributes['style'] ?? '');

            if ($this->styleResolver->isAbsolutelyPositioned($style)) {
                $this->layoutAbsoluteNode(
                    $node,
                    $style,
                    $container,
                    $canvasHeight,
                    $lines,
                    $images,
                    $decorations,
                    $imageCount,
                    $activeClipBox,
                );
                continue;
            }

            $availableBox = [
                'x' => $container['x'],
                'y' => $container['y'] + $consumedHeight,
                'width' => $container['width'],
                'height' => max($container['height'] - $consumedHeight, $zero),
            ];

            $consumedHeight += $this->layoutFlowNode(
                $node,
                $style,
                $availableBox,
                $canvasHeight,
                $lines,
                $images,
                $decorations,
                $imageCount,
                $activeClipBox,
            );
        }

        return $consumedHeight;
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $availableBox
     * @param list<LayoutLine> $lines
     * @param list<LayoutImage> $images
     * @param list<LayoutDecoration> $decorations
     * @param array{x: float, y: float, width: float, height: float}|null $activeClipBox
     */
    private function layoutFlowNode(
        \LibreSign\XObjectTemplate\Html\Node $node,
        StyleMap $style,
        array $availableBox,
        float $canvasHeight,
        array &$lines,
        array &$images,
        array &$decorations,
        int &$imageCount,
        ?array $activeClipBox,
    ): float {
        ['margin' => $margin, 'box' => $box] = $this->boxResolver->resolveFlowPlacement($node, $style, $availableBox);

        $renderedHeight = $this->renderResolvedNode(
            $node,
            $style,
            $box,
            $canvasHeight,
            $lines,
            $images,
            $decorations,
            $imageCount,
            $activeClipBox,
        );

        return $margin['top'] + $renderedHeight + $margin['bottom'];
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $container
     * @param list<LayoutLine> $lines
     * @param list<LayoutImage> $images
     * @param list<LayoutDecoration> $decorations
     * @param array{x: float, y: float, width: float, height: float}|null $activeClipBox
     */
    private function layoutAbsoluteNode(
        \LibreSign\XObjectTemplate\Html\Node $node,
        StyleMap $style,
        array $container,
        float $canvasHeight,
        array &$lines,
        array &$images,
        array &$decorations,
        int &$imageCount,
        ?array $activeClipBox,
    ): void {
        $this->renderResolvedNode(
            $node,
            $style,
            $this->boxResolver->resolveAbsoluteBox($node, $style, $container),
            $canvasHeight,
            $lines,
            $images,
            $decorations,
            $imageCount,
            $activeClipBox,
        );
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $box
     * @param list<LayoutLine> $lines
     * @param list<LayoutImage> $images
     * @param list<LayoutDecoration> $decorations
     * @param array{x: float, y: float, width: float, height: float}|null $activeClipBox
     */
    private function renderResolvedNode(
        \LibreSign\XObjectTemplate\Html\Node $node,
        StyleMap $style,
        array $box,
        float $canvasHeight,
        array &$lines,
        array &$images,
        array &$decorations,
        int &$imageCount,
        ?array $activeClipBox,
    ): float {
        if ($node->tag === 'br') {
            return 12.0;
        }

        if ($node->tag === 'img') {
            return $this->renderImage($node, $box, $canvasHeight, $images, $imageCount, $activeClipBox);
        }

        if (strtolower(trim($this->styleResolver->styleValue($style, 'display', ''))) === 'flex') {
            return $this->renderFlexContainer(
                $node,
                $style,
                $box,
                $canvasHeight,
                $lines,
                $images,
                $decorations,
                $imageCount,
                $activeClipBox,
            );
        }

        return $this->renderBlockContainer(
            $node,
            $style,
            $box,
            $canvasHeight,
            $lines,
            $images,
            $decorations,
            $imageCount,
            $activeClipBox,
        );
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $box
     * @param list<LayoutLine> $lines
     * @param list<LayoutImage> $images
     * @param list<LayoutDecoration> $decorations
     * @param array{x: float, y: float, width: float, height: float}|null $activeClipBox
     */
    private function renderBlockContainer(
        \LibreSign\XObjectTemplate\Html\Node $node,
        StyleMap $style,
        array $box,
        float $canvasHeight,
        array &$lines,
        array &$images,
        array &$decorations,
        int &$imageCount,
        ?array $activeClipBox,
    ): float {
        ['padding' => $padding, 'contentBox' => $contentBox] = $this->boxResolver->resolveContentBox($style, $box);
        $localClipBox = $this->resolveClipBox($style, $box, $activeClipBox);

        $contentHeight = 0.0;
        if (trim($node->text) !== '') {
            $contentHeight += $this->renderTextLine($node, $style, $contentBox, $canvasHeight, $lines, $localClipBox);
        }

        if ($node->children !== []) {
            $contentHeight += $this->layoutNodes(
                $node->children,
                $this->boxResolver->createChildContainer($contentBox, $contentHeight),
                $canvasHeight,
                $lines,
                $images,
                $decorations,
                $imageCount,
                $localClipBox,
            );
        }

        $renderedHeight = $localClipBox === null
            ? $this->boxResolver->resolveAutoContainerHeight($box['height'], $padding, $contentHeight)
            : $this->boxResolver->resolveFixedContainerHeight($box['height'], $padding, $contentHeight);

        $this->appendDecoration($style, $box, $renderedHeight, $canvasHeight, $decorations);

        return $renderedHeight;
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $box
     * @param list<LayoutLine> $lines
     * @param list<LayoutImage> $images
     * @param list<LayoutDecoration> $decorations
     * @param array{x: float, y: float, width: float, height: float}|null $activeClipBox
     */
    private function renderFlexContainer(
        \LibreSign\XObjectTemplate\Html\Node $node,
        StyleMap $style,
        array $box,
        float $canvasHeight,
        array &$lines,
        array &$images,
        array &$decorations,
        int &$imageCount,
        ?array $activeClipBox,
    ): float {
        ['padding' => $padding, 'contentBox' => $contentBox] = $this->boxResolver->resolveContentBox($style, $box);
        $localClipBox = $this->resolveClipBox($style, $box, $activeClipBox);

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
            $decorations,
            $imageCount,
            $localClipBox,
        );

        if ($items === []) {
            $renderedHeight = $localClipBox === null
                ? $this->boxResolver->resolveAutoContainerHeight($box['height'], $padding, 0.0)
                : $this->boxResolver->resolveFixedContainerHeight($box['height'], $padding, 0.0);
            $this->appendDecoration($style, $box, $renderedHeight, $canvasHeight, $decorations);

            return $renderedHeight;
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
                $decorations,
                $imageCount,
                $localClipBox,
            );

            $cursor += $this->flexPlanner->advanceCursor($item, $direction, $metrics['gap']);
        }

        $contentHeight = $direction === 'row' ? $metrics['crossAxisSize'] : $metrics['totalMainAxisSize'];
        $renderedHeight = $localClipBox === null
            ? $this->boxResolver->resolveAutoContainerHeight($box['height'], $padding, $contentHeight)
            : $this->boxResolver->resolveFixedContainerHeight($box['height'], $padding, $contentHeight);
        $this->appendDecoration($style, $box, $renderedHeight, $canvasHeight, $decorations);

        return $renderedHeight;
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $box
     * @param list<LayoutLine> $lines
     * @param array{x: float, y: float, width: float, height: float}|null $activeClipBox
     */
    private function renderTextLine(
        \LibreSign\XObjectTemplate\Html\Node $node,
        StyleMap $style,
        array $box,
        float $canvasHeight,
        array &$lines,
        ?array $activeClipBox,
    ): float {
        $result = $this->textLayouter->layout($node->text, $style, $box, $canvasHeight, $activeClipBox);
        foreach ($result['lines'] as $line) {
            $lines[] = $line;
        }

        return $result['consumedHeight'];
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $box
     * @param list<LayoutImage> $images
     * @param array{x: float, y: float, width: float, height: float}|null $activeClipBox
     */
    private function renderImage(
        \LibreSign\XObjectTemplate\Html\Node $node,
        array $box,
        float $canvasHeight,
        array &$images,
        int &$imageCount,
        ?array $activeClipBox,
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
            clipBox: $this->toPdfClipBox($activeClipBox, $canvasHeight),
        );
        ++$imageCount;

        return $height;
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $box
     * @param array{x: float, y: float, width: float, height: float} $contentBox
     * @param list<LayoutLine> $lines
     * @param list<LayoutImage> $images
     * @param list<LayoutDecoration> $decorations
     * @param array{x: float, y: float, width: float, height: float}|null $activeClipBox
    * @return list<array{
    *     node: \LibreSign\XObjectTemplate\Html\Node,
    *     style: StyleMap,
    *     size: array{width: float, height: float}
    * }>
     */
    private function collectFlexItems(
        \LibreSign\XObjectTemplate\Html\Node $node,
        array $box,
        array $contentBox,
        float $canvasHeight,
        array &$lines,
        array &$images,
        array &$decorations,
        int &$imageCount,
        ?array $activeClipBox,
    ): array {
        $items = [];

        foreach ($node->children as $child) {
            $childStyle = $this->styleParser->parse($child->attributes['style'] ?? '');
            if ($this->styleResolver->isAbsolutelyPositioned($childStyle)) {
                $this->layoutAbsoluteNode(
                    $child,
                    $childStyle,
                    $box,
                    $canvasHeight,
                    $lines,
                    $images,
                    $decorations,
                    $imageCount,
                    $activeClipBox,
                );
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

    /**
     * @param array{x: float, y: float, width: float, height: float} $box
     * @param list<LayoutDecoration> $decorations
     */
    private function appendDecoration(
        StyleMap $style,
        array $box,
        float $renderedHeight,
        float $canvasHeight,
        array &$decorations,
    ): void {
        $fillColor = trim($this->styleResolver->styleValue($style, 'background-color', ''));
        $strokeColor = trim($this->styleResolver->styleValue($style, 'border-color', ''));
        $strokeWidth = $this->styleResolver->toPoints(
            $this->styleResolver->styleValue($style, 'border-width', '0'),
        );
        $borderRadius = $this->styleResolver->toPoints($this->styleResolver->styleValue($style, 'border-radius', '0'));

        if ($fillColor === '' && ($strokeColor === '' || $strokeWidth <= 0.0)) {
            return;
        }

        $height = $renderedHeight > 0.0 ? $renderedHeight : $box['height'];
        if ($box['width'] <= 0.0 || $height <= 0.0) {
            return;
        }

        $decorations[] = new LayoutDecoration(
            x: $box['x'],
            y: max($canvasHeight - ($box['y'] + $height), 0.0),
            width: $box['width'],
            height: $height,
            fillColor: $fillColor !== '' ? $fillColor : null,
            strokeColor: $strokeColor !== '' ? $strokeColor : null,
            strokeWidth: $strokeWidth,
            borderRadius: $borderRadius,
        );
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $box
     * @param array{x: float, y: float, width: float, height: float}|null $activeClipBox
     * @return array{x: float, y: float, width: float, height: float}|null
     */
    private function resolveClipBox(StyleMap $style, array $box, ?array $activeClipBox): ?array
    {
        $currentClipBox = $activeClipBox;
        if (
            strtolower(trim($this->styleResolver->styleValue($style, 'overflow', 'visible'))) === 'hidden'
            && $box['width'] > 0.0
            && $box['height'] > 0.0
        ) {
            $currentClipBox = $activeClipBox === null ? $box : $this->intersectBoxes($activeClipBox, $box);
        }

        return $currentClipBox;
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $first
     * @param array{x: float, y: float, width: float, height: float} $second
     * @return array{x: float, y: float, width: float, height: float}
     */
    private function intersectBoxes(array $first, array $second): array
    {
        $x = max($first['x'], $second['x']);
        $y = max($first['y'], $second['y']);
        $right = min($first['x'] + $first['width'], $second['x'] + $second['width']);
        $bottom = min($first['y'] + $first['height'], $second['y'] + $second['height']);

        return [
            'x' => $x,
            'y' => $y,
            'width' => max($right - $x, 0.0),
            'height' => max($bottom - $y, 0.0),
        ];
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
}
