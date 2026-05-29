<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Layout;

use LibreSign\XObjectTemplate\Css\StyleMap;
use LibreSign\XObjectTemplate\Html\Node;

/** @internal */
final readonly class StructuredBoxResolver
{
    public function __construct(private LayoutStyleResolver $styleResolver)
    {
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $availableBox
     * @return array{
     *     margin: array{top: float, right: float, bottom: float, left: float},
     *     box: array{x: float, y: float, width: float, height: float}
     * }
     */
    public function resolveFlowPlacement(Node $node, StyleMap $style, array $availableBox): array
    {
        $margin = $this->styleResolver->parseBoxSpacingRelative(
            $this->styleResolver->styleValue($style, 'margin', '0'),
            $availableBox['width'],
            $availableBox['height'],
        );

        return [
            'margin' => $margin,
            'box' => [
                'x' => $availableBox['x'] + $margin['left'],
                'y' => $availableBox['y'] + $margin['top'],
                'width' => $this->resolveFlowWidth($node, $style, $availableBox, $margin),
                'height' => $this->resolveFlowHeight($node, $style, $availableBox),
            ],
        ];
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $container
     * @return array{x: float, y: float, width: float, height: float}
     */
    public function resolveAbsoluteBox(Node $node, StyleMap $style, array $container): array
    {
        $margin = $this->styleResolver->parseBoxSpacingRelative(
            $this->styleResolver->styleValue($style, 'margin', '0'),
            $container['width'],
            $container['height'],
        );

        $resolvedWidth = $this->resolveAbsoluteWidth($node, $style, $container, $margin);
        $resolvedHeight = $this->resolveAbsoluteHeight($node, $style, $container, $margin);

        return [
            'x' => $this->resolveAbsoluteX($style, $container, $resolvedWidth, $margin),
            'y' => $this->resolveAbsoluteY($style, $container, $resolvedHeight, $margin),
            'width' => $resolvedWidth,
            'height' => $resolvedHeight,
        ];
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $box
     * @return array{
     *     padding: array{top: float, right: float, bottom: float, left: float},
     *     contentBox: array{x: float, y: float, width: float, height: float}
     * }
     */
    public function resolveContentBox(StyleMap $style, array $box): array
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
    public function createChildContainer(array $contentBox, float $consumedHeight): array
    {
        return [
            'x' => $contentBox['x'],
            'y' => $contentBox['y'] + $consumedHeight,
            'width' => $contentBox['width'],
            'height' => max($contentBox['height'] - $consumedHeight, $this->styleResolver->toPoints('0')),
        ];
    }

    /**
     * @param array{top: float, right: float, bottom: float, left: float} $padding
     */
    public function resolveAutoContainerHeight(
        float $resolvedHeight,
        array $padding,
        float $contentHeight,
    ): float {
        $autoHeight = $padding['top'] + $contentHeight + $padding['bottom'];

        return max($resolvedHeight, $autoHeight);
    }

    /**
     * @param array{top: float, right: float, bottom: float, left: float} $padding
     */
    public function resolveFixedContainerHeight(
        float $resolvedHeight,
        array $padding,
        float $contentHeight,
    ): float {
        if ($resolvedHeight > 0.0) {
            return $resolvedHeight;
        }

        return $padding['top'] + $contentHeight + $padding['bottom'];
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
