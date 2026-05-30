<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace LibreSign\XObjectTemplate\Pdf\Svg;

use DOMElement;

/**
 * Computes cumulative SVG transform matrices for DOM elements.
 *
 * This class handles the SVG transform attribute parsing and matrix math
 * needed to convert element coordinates to the document's coordinate space.
 * It walks the ancestor chain to accumulate inherited transforms.
 */
final class SvgTransformResolver
{
    /**
     * Compute the cumulative transform matrix for an element.
     *
     * Traverses ancestors from the SVG root down to the element, multiplying
     * each transform to produce the net affine transformation matrix.
     *
     * @param DOMElement $element The target element
     * @return array{0:float,1:float,2:float,3:float,4:float,5:float} 6-element affine matrix
     */
    public function resolveElementTransformMatrix(DOMElement $element): array
    {
        $matrix = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
        $ancestors = [];
        $cursor = $element;

        while ($cursor instanceof DOMElement) {
            $ancestors[] = $cursor;
            $cursor = $cursor->parentNode;
        }

        for ($index = count($ancestors) - 1; $index >= 0; --$index) {
            $transform = trim($ancestors[$index]->getAttribute('transform'));
            if ($transform === '') {
                continue;
            }

            $matrix = $this->multiplyMatrices($matrix, $this->parseTransformList($transform));
        }

        return $matrix;
    }

    /**
     * Apply a transform matrix to a point.
     *
     * @param array{0:float,1:float,2:float,3:float,4:float,5:float} $matrix The transform matrix
     * @param float $x The input X coordinate
     * @param float $y The input Y coordinate
     * @return array{0:float,1:float} Transformed [x, y]
     */
    public function applyTransformToPoint(array $matrix, float $x, float $y): array
    {
        return [
            $matrix[0] * $x + $matrix[2] * $y + $matrix[4],
            $matrix[1] * $x + $matrix[3] * $y + $matrix[5],
        ];
    }

    /**
     * Parse a transform attribute string into a composite matrix.
     *
     * Handles matrix, translate, scale, rotate, skewX, and skewY transforms.
     *
     * @return array{0:float,1:float,2:float,3:float,4:float,5:float}
     */
    private function parseTransformList(string $transform): array
    {
        $matrix = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];

        if (
            preg_match_all(
                '/(matrix|translate|scale|rotate|skewX|skewY)\s*\(([^)]*)\)/i',
                $transform,
                $matches,
                PREG_SET_ORDER,
            ) < 1
        ) {
            return $matrix;
        }

        foreach ($matches as $match) {
            $operatorName = strtolower($match[1]);
            $args = preg_split('/[\s,]+/', trim($match[2]));
            if (!is_array($args)) {
                continue;
            }

            $values = [];
            foreach ($args as $arg) {
                if ($arg === '') {
                    continue;
                }

                $values[] = (float) $arg;
            }

            $operationMatrix = match ($operatorName) {
                'matrix' => count($values) >= 6
                    ? [$values[0], $values[1], $values[2], $values[3], $values[4], $values[5]]
                    : [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
                'translate' => [1.0, 0.0, 0.0, 1.0, $values[0] ?? 0.0, $values[1] ?? 0.0],
                'scale' => [$values[0] ?? 1.0, 0.0, 0.0, $values[1] ?? ($values[0] ?? 1.0), 0.0, 0.0],
                'rotate' => $this->buildRotateMatrix($values),
                'skewx' => [1.0, 0.0, tan(deg2rad($values[0] ?? 0.0)), 1.0, 0.0, 0.0],
                'skewy' => [1.0, tan(deg2rad($values[0] ?? 0.0)), 0.0, 1.0, 0.0, 0.0],
                default => [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
            };

            $matrix = $this->multiplyMatrices($matrix, $operationMatrix);
        }

        return $matrix;
    }

    /**
     * Build a rotation matrix, optionally with rotation center offset.
     *
     * @param list<float> $values Angle[, cx, cy] values
     * @return array{0:float,1:float,2:float,3:float,4:float,5:float}
     */
    private function buildRotateMatrix(array $values): array
    {
        $angle = deg2rad($values[0] ?? 0.0);
        $cos = cos($angle);
        $sin = sin($angle);

        $rotation = [$cos, $sin, -$sin, $cos, 0.0, 0.0];

        if (count($values) < 3) {
            return $rotation;
        }

        $centerX = $values[1];
        $centerY = $values[2];

        return $this->multiplyMatrices(
            $this->multiplyMatrices([1.0, 0.0, 0.0, 1.0, $centerX, $centerY], $rotation),
            [1.0, 0.0, 0.0, 1.0, -$centerX, -$centerY],
        );
    }

    /**
     * Multiply two SVG affine matrices.
     *
     * @param array{0:float,1:float,2:float,3:float,4:float,5:float} $left
     * @param array{0:float,1:float,2:float,3:float,4:float,5:float} $right
     * @return array{0:float,1:float,2:float,3:float,4:float,5:float}
     */
    private function multiplyMatrices(array $left, array $right): array
    {
        return [
            $left[0] * $right[0] + $left[2] * $right[1],
            $left[1] * $right[0] + $left[3] * $right[1],
            $left[0] * $right[2] + $left[2] * $right[3],
            $left[1] * $right[2] + $left[3] * $right[3],
            $left[0] * $right[4] + $left[2] * $right[5] + $left[4],
            $left[1] * $right[4] + $left[3] * $right[5] + $left[5],
        ];
    }
}
