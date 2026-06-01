<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace LibreSign\XObjectTemplate\Pdf\Svg;

use LibreSign\XObjectTemplate\Pdf\Svg\SvgArcMath;

/**
 * Converts SVG arc commands to cubic Bézier curve approximations.
 *
 * This class encapsulates the mathematical transformation of SVG arc path
 * commands (A/a) into a series of cubic Bézier curves, which are directly
 * supported by the PDF specification.
 *
 * The algorithm implements the SVG 2 specification's arc-to-Bézier conversion,
 * decomposing the arc into multiple segments for accurate curve approximation.
 */
final readonly class SvgArcConverter
{
    public function __construct(
        private SvgArcMath $math = new SvgArcMath(),
    ) {
    }

    /**
     * Convert SVG arc command parameters to cubic Bézier curves.
     *
     * This method takes the arc parameters as specified in SVG and converts
     * them to an array of cubic Bézier curve control points that approximate
     * the arc within the PDF coordinate space.
     *
     * @param float $fromX Starting X coordinate
     * @param float $fromY Starting Y coordinate
     * @param float $rx X-axis radius
     * @param float $ry Y-axis radius
     * @param float $rotation Rotation angle in degrees
     * @param int $largeArc Large arc flag (0 or 1)
     * @param int $sweep Sweep flag (0 or 1)
     * @param float $toX Ending X coordinate
     * @param float $toY Ending Y coordinate
     * @return array<int, array<int, float>> Array of cubic Bézier control points
     */
    public function arcToBezierCurves(
        float $fromX,
        float $fromY,
        float $radiusX,
        float $radiusY,
        float $rotation,
        int $largeArc,
        int $sweep,
        float $toX,
        float $toY,
    ): array {
        if (abs($toX - $fromX) < 1e-10 && abs($toY - $fromY) < 1e-10) {
            return [];
        }

        if ($radiusX < 1e-10 || $radiusY < 1e-10) {
            return [[$toX, $toY, $toX, $toY, $toX, $toY]];
        }

        $theta    = deg2rad($rotation);
        $params = new ArcParams(
            $fromX,
            $fromY,
            $toX,
            $toY,
            $radiusX,
            $radiusY,
            cos($theta),
            sin($theta),
            $largeArc,
            $sweep,
        );

        $params = $this->math->normalizeArcRadii($params);

        [$centerX, $centerY] = $this->math->calculateArcCenter($params);

        [$startAngle, $deltaAngle] = $this->math->calculateArcAngles($params);

        return $this->math->generateArcCurves(
            $params,
            $centerX,
            $centerY,
            $startAngle,
            $deltaAngle,
        );
    }
}
