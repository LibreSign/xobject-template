<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace LibreSign\XObjectTemplate\Pdf\Svg;

use DOMElement;

/**
 * Builds PDF path command strings from SVG shape elements.
 *
 * Handles path, polygon, polyline, rect, circle, ellipse, and line SVG elements,
 * converting their geometry to PDF path operators while applying coordinate transforms.
 */
final class SvgElementPathBuilder
{
    public function __construct(
        private SvgTransformResolver $transformResolver = new SvgTransformResolver(),
        private SvgPathCommandParser $pathParser = new SvgPathCommandParser(),
    ) {
    }

    /**
     * Build a PDF path string for the given SVG element.
     *
     * @param DOMElement $element        The SVG shape element
     * @param float      $minX           ViewBox X origin
     * @param float      $maxY           ViewBox bottom Y (for Y-axis flip)
     * @param string     $source         Source identifier for error messages
     * @param array{0:float,1:float,2:float,3:float,4:float,5:float} $transformMatrix Cumulative transform
     * @return ?string PDF path command string, or null if element is invalid/empty
     */
    public function buildElementPath(
        DOMElement $element,
        float $minX,
        float $maxY,
        string $source,
        array $transformMatrix,
    ): ?string {
        $name = strtolower((string) $element->localName);

        return match ($name) {
            'path'     => $this->buildPathElementPath($element, $minX, $maxY, $source, $transformMatrix),
            'polygon'  => $this->buildPolygonElementPath($element, $minX, $maxY, $transformMatrix),
            'polyline' => $this->buildPolylineElementPath($element, $minX, $maxY, $transformMatrix),
            'rect'     => $this->buildRectElementPath($element, $minX, $maxY, $transformMatrix),
            'circle'   => $this->buildCircleElementPath($element, $minX, $maxY, $transformMatrix),
            'ellipse'  => $this->buildEllipseElementPath($element, $minX, $maxY, $transformMatrix),
            'line'     => $this->buildLineElementPath($element, $minX, $maxY, $transformMatrix),
            default    => null,
        };
    }

    /**
     * @param array{0:float,1:float,2:float,3:float,4:float,5:float} $transformMatrix
     */
    private function buildPathElementPath(
        DOMElement $element,
        float $minX,
        float $maxY,
        string $source,
        array $transformMatrix,
    ): ?string {
        $d = trim($element->getAttribute('d'));
        if ($d === '') {
            return null;
        }

        return $this->pathParser->convertPathData($d, $minX, $maxY, $source, $transformMatrix);
    }

    /**
     * @param array{0:float,1:float,2:float,3:float,4:float,5:float} $transformMatrix
     */
    private function buildPolygonElementPath(
        DOMElement $element,
        float $minX,
        float $maxY,
        array $transformMatrix,
    ): ?string {
        $points = trim($element->getAttribute('points'));
        if ($points === '') {
            return null;
        }

        if (preg_match_all('/[-+]?\d*\.?\d+(?:[eE][-+]?\d+)?/', $points, $matches) < 1) {
            return null;
        }

        $raw = $matches[0];
        $rawCount = count($raw);
        if ($rawCount < 4 || $rawCount % 2 !== 0) {
            return null;
        }

        $commands = [];
        $x = (float) $raw[0];
        $y = (float) $raw[1];
        [$x, $y] = $this->transformResolver->applyTransformToPoint($transformMatrix, $x, $y);
        $commands[] = sprintf('%F %F m', $x - $minX, $maxY - $y);

        for ($index = 2; $index < $rawCount; $index += 2) {
            $px = (float) $raw[$index];
            $py = (float) $raw[$index + 1];
            [$px, $py] = $this->transformResolver->applyTransformToPoint($transformMatrix, $px, $py);
            $commands[] = sprintf('%F %F l', $px - $minX, $maxY - $py);
        }

        $commands[] = 'h';

        return implode("\n", $commands);
    }

    /**
     * @param array{0:float,1:float,2:float,3:float,4:float,5:float} $transformMatrix
     */
    private function buildRectElementPath(
        DOMElement $element,
        float $minX,
        float $maxY,
        array $transformMatrix,
    ): ?string {
        $x = $this->extractNumericSvgLength($element->getAttribute('x'));
        $y = $this->extractNumericSvgLength($element->getAttribute('y'));
        $width = $this->extractNumericSvgLength($element->getAttribute('width'));
        $height = $this->extractNumericSvgLength($element->getAttribute('height'));

        if ($width <= 0.0 || $height <= 0.0) {
            return null;
        }

        $points = [
            [$x, $y],
            [$x + $width, $y],
            [$x + $width, $y + $height],
            [$x, $y + $height],
        ];

        $commands = [];
        foreach ($points as $index => [$px, $py]) {
            [$tx, $ty] = $this->transformResolver->applyTransformToPoint($transformMatrix, $px, $py);
            $commands[] = sprintf('%F %F %s', $tx - $minX, $maxY - $ty, $index === 0 ? 'm' : 'l');
        }

        $commands[] = 'h';

        return implode("\n", $commands);
    }

    /**
     * @param array{0:float,1:float,2:float,3:float,4:float,5:float} $transformMatrix
     */
    private function buildPolylineElementPath(
        DOMElement $element,
        float $minX,
        float $maxY,
        array $transformMatrix,
    ): ?string {
        $points = trim($element->getAttribute('points'));
        if ($points === '') {
            return null;
        }

        if (preg_match_all('/[-+]?\d*\.?\d+(?:[eE][-+]?\d+)?/', $points, $matches) < 1) {
            return null;
        }

        $raw = $matches[0];
        $rawCount = count($raw);
        if ($rawCount < 4 || $rawCount % 2 !== 0) {
            return null;
        }

        $commands = [];
        [$firstX, $firstY] = $this->transformResolver->applyTransformToPoint(
            $transformMatrix,
            (float) $raw[0],
            (float) $raw[1],
        );
        $commands[] = sprintf('%F %F m', $firstX - $minX, $maxY - $firstY);

        for ($index = 2; $index < $rawCount; $index += 2) {
            [$tx, $ty] = $this->transformResolver->applyTransformToPoint(
                $transformMatrix,
                (float) $raw[$index],
                (float) $raw[$index + 1],
            );
            $commands[] = sprintf('%F %F l', $tx - $minX, $maxY - $ty);
        }

        return implode("\n", $commands);
    }

    /**
     * @param array{0:float,1:float,2:float,3:float,4:float,5:float} $transformMatrix
     */
    private function buildCircleElementPath(
        DOMElement $element,
        float $minX,
        float $maxY,
        array $transformMatrix,
    ): ?string {
        $cx = $this->extractNumericSvgLength($element->getAttribute('cx'));
        $cy = $this->extractNumericSvgLength($element->getAttribute('cy'));
        $r  = $this->extractNumericSvgLength($element->getAttribute('r'));

        if ($r <= 0.0) {
            return null;
        }

        return $this->buildEllipsePath($cx, $cy, $r, $r, $minX, $maxY, $transformMatrix);
    }

    /**
     * @param array{0:float,1:float,2:float,3:float,4:float,5:float} $transformMatrix
     */
    private function buildEllipseElementPath(
        DOMElement $element,
        float $minX,
        float $maxY,
        array $transformMatrix,
    ): ?string {
        $cx = $this->extractNumericSvgLength($element->getAttribute('cx'));
        $cy = $this->extractNumericSvgLength($element->getAttribute('cy'));
        $rx = $this->extractNumericSvgLength($element->getAttribute('rx'));
        $ry = $this->extractNumericSvgLength($element->getAttribute('ry'));

        if ($rx <= 0.0 || $ry <= 0.0) {
            return null;
        }

        return $this->buildEllipsePath($cx, $cy, $rx, $ry, $minX, $maxY, $transformMatrix);
    }

    /**
     * Approximate an axis-aligned ellipse with 4 cubic Bézier curves (κ = 0.5522847498).
     *
     * @param array{0:float,1:float,2:float,3:float,4:float,5:float} $transformMatrix
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    private function buildEllipsePath(
        float $cx,
        float $cy,
        float $rx,
        float $ry,
        float $minX,
        float $maxY,
        array $transformMatrix,
    ): string {
        $k  = 0.5522847498;
        $kx = $k * $rx;
        $ky = $k * $ry;

        $topX = $cx;
        $topY = $cy - $ry;
        $rightX = $cx + $rx;
        $rightY = $cy;
        $bottomX = $cx;
        $bottomY = $cy + $ry;
        $leftX = $cx - $rx;
        $leftY = $cy;

        [$topX, $topY] = $this->transformResolver->applyTransformToPoint($transformMatrix, $topX, $topY);
        [$rightX, $rightY] = $this->transformResolver->applyTransformToPoint($transformMatrix, $rightX, $rightY);
        [$bottomX, $bottomY] = $this->transformResolver->applyTransformToPoint($transformMatrix, $bottomX, $bottomY);
        [$leftX, $leftY] = $this->transformResolver->applyTransformToPoint($transformMatrix, $leftX, $leftY);

        [$cpTopRight1X, $cpTopRight1Y] = $this->transformResolver->applyTransformToPoint(
            $transformMatrix,
            $cx + $kx,
            $cy - $ry,
        );
        [$cpTopRight2X, $cpTopRight2Y] = $this->transformResolver->applyTransformToPoint(
            $transformMatrix,
            $cx + $rx,
            $cy - $ky,
        );
        [$cpRightBottom1X, $cpRightBottom1Y] = $this->transformResolver->applyTransformToPoint(
            $transformMatrix,
            $cx + $rx,
            $cy + $ky,
        );
        [$cpRightBottom2X, $cpRightBottom2Y] = $this->transformResolver->applyTransformToPoint(
            $transformMatrix,
            $cx + $kx,
            $cy + $ry,
        );
        [$cpBottomLeft1X, $cpBottomLeft1Y] = $this->transformResolver->applyTransformToPoint(
            $transformMatrix,
            $cx - $kx,
            $cy + $ry,
        );
        [$cpBottomLeft2X, $cpBottomLeft2Y] = $this->transformResolver->applyTransformToPoint(
            $transformMatrix,
            $cx - $rx,
            $cy + $ky,
        );
        [$cpLeftTop1X, $cpLeftTop1Y] = $this->transformResolver->applyTransformToPoint(
            $transformMatrix,
            $cx - $rx,
            $cy - $ky,
        );
        [$cpLeftTop2X, $cpLeftTop2Y] = $this->transformResolver->applyTransformToPoint(
            $transformMatrix,
            $cx - $kx,
            $cy - $ry,
        );

        $commands = [];
        $commands[] = sprintf('%F %F m', $topX - $minX, $maxY - $topY);
        $commands[] = sprintf(
            '%F %F %F %F %F %F c',
            $cpTopRight1X - $minX,
            $maxY - $cpTopRight1Y,
            $cpTopRight2X - $minX,
            $maxY - $cpTopRight2Y,
            $rightX - $minX,
            $maxY - $rightY,
        );
        $commands[] = sprintf(
            '%F %F %F %F %F %F c',
            $cpRightBottom1X - $minX,
            $maxY - $cpRightBottom1Y,
            $cpRightBottom2X - $minX,
            $maxY - $cpRightBottom2Y,
            $bottomX - $minX,
            $maxY - $bottomY,
        );
        $commands[] = sprintf(
            '%F %F %F %F %F %F c',
            $cpBottomLeft1X - $minX,
            $maxY - $cpBottomLeft1Y,
            $cpBottomLeft2X - $minX,
            $maxY - $cpBottomLeft2Y,
            $leftX - $minX,
            $maxY - $leftY,
        );
        $commands[] = sprintf(
            '%F %F %F %F %F %F c',
            $cpLeftTop1X - $minX,
            $maxY - $cpLeftTop1Y,
            $cpLeftTop2X - $minX,
            $maxY - $cpLeftTop2Y,
            $topX - $minX,
            $maxY - $topY,
        );
        $commands[] = 'h';

        return implode("\n", $commands);
    }

    /**
     * @param array{0:float,1:float,2:float,3:float,4:float,5:float} $transformMatrix
     */
    private function buildLineElementPath(DOMElement $element, float $minX, float $maxY, array $transformMatrix): string
    {
        $x1 = $this->extractNumericSvgLength($element->getAttribute('x1'));
        $y1 = $this->extractNumericSvgLength($element->getAttribute('y1'));
        $x2 = $this->extractNumericSvgLength($element->getAttribute('x2'));
        $y2 = $this->extractNumericSvgLength($element->getAttribute('y2'));

        [$x1, $y1] = $this->transformResolver->applyTransformToPoint($transformMatrix, $x1, $y1);
        [$x2, $y2] = $this->transformResolver->applyTransformToPoint($transformMatrix, $x2, $y2);

        return implode("\n", [
            sprintf('%F %F m', $x1 - $minX, $maxY - $y1),
            sprintf('%F %F l', $x2 - $minX, $maxY - $y2),
        ]);
    }

    private function extractNumericSvgLength(string $value): float
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return 0.0;
        }

        if (preg_match('/^[-+]?\d*\.?\d+(?:[eE][-+]?\d+)?/', $trimmed, $matches) !== 1) {
            return 0.0;
        }

        return (float) $matches[0];
    }
}
