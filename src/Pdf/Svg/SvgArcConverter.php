<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace LibreSign\XObjectTemplate\Pdf\Svg;

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
final class SvgArcConverter
{
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

        // Step 1: Normalize radii
        $params = $this->normalizeArcRadii($params);

        // Step 2: Calculate center point
        [$centerX, $centerY] = $this->calculateArcCenter($params);

        // Step 3: Calculate angles and deltas
        [$startAngle, $deltaAngle] = $this->calculateArcAngles($params, $centerX, $centerY);

        // Step 4: Generate cubic Bézier curves
        return $this->generateArcCurves(
            $centerX,
            $centerY,
            $params->radiusX,
            $params->radiusY,
            $params->cosTh,
            $params->sinTh,
            $startAngle,
            $deltaAngle,
        );
    }

    /**
     * Normalize arc radii to satisfy SVG constraints.
     *
     * If the given radii are too small, they are scaled up to ensure the arc
     * can connect the start and end points.
     */
    private function normalizeArcRadii(ArcParams $params): ArcParams
    {
        $deltaX2 = ($params->fromX - $params->toX) / 2.0;
        $deltaY2 = ($params->fromY - $params->toY) / 2.0;
        $primeX  =  $params->cosTh * $deltaX2 + $params->sinTh * $deltaY2;
        $primeY  = -$params->sinTh * $deltaX2 + $params->cosTh * $deltaY2;

        $radiusX2   = $params->radiusX * $params->radiusX;
        $radiusY2   = $params->radiusY * $params->radiusY;
        $primeX2   = $primeX * $primeX;
        $primeY2   = $primeY * $primeY;
        $scale = $primeX2 / $radiusX2 + $primeY2 / $radiusY2;
        if ($scale <= 1.0) {
            return $params;
        }

        $scaleFactor = sqrt($scale);

        return $params->withRadii($params->radiusX * $scaleFactor, $params->radiusY * $scaleFactor);
    }

    /**
     * Calculate the center point of the arc.
     *
     * @return array{0:float,1:float} [$cx, $cy]
     */
    private function calculateArcCenter(ArcParams $params): array
    {
        [$primeX, $primeY] = $this->calculatePrimeCoordinates($params);

        $radiusX2   = $params->radiusX * $params->radiusX;
        $radiusY2   = $params->radiusY * $params->radiusY;
        $primeX2   = $primeX * $primeX;
        $primeY2   = $primeY * $primeY;
        $numerator   = max(0.0, $radiusX2 * $radiusY2 - $radiusX2 * $primeY2 - $radiusY2 * $primeX2);
        $denominator   = $radiusX2 * $primeY2 + $radiusY2 * $primeX2;
        $squareRoot    = $denominator > 1e-10 ? sqrt($numerator / $denominator) : 0.0;
        if ($params->largeArc === $params->sweep) {
            $squareRoot = -$squareRoot;
        }

        $centerX1 =  $squareRoot * $params->radiusX * $primeY / $params->radiusY;
        $centerY1 = -$squareRoot * $params->radiusY * $primeX / $params->radiusX;

        $midX = ($params->fromX + $params->toX) / 2.0;
        $midY = ($params->fromY + $params->toY) / 2.0;
        $centerX   = $params->cosTh * $centerX1 - $params->sinTh * $centerY1 + $midX;
        $centerY   = $params->sinTh * $centerX1 + $params->cosTh * $centerY1 + $midY;

        return [$centerX, $centerY];
    }

    /**
     * Calculate start angle and total angle delta for the arc.
     *
     * @return array{0:float,1:float} [$startAngle, $dAngle]
     */
    private function calculateArcAngles(ArcParams $params, float $centerX, float $centerY): array
    {
        [$primeX, $primeY] = $this->calculatePrimeCoordinates($params);

        $vectorUX = $primeX / $params->radiusX;
        $vectorUY = $primeY / $params->radiusY;
        $vectorVX = -$primeX / $params->radiusX;
        $vectorVY = -$primeY / $params->radiusY;

        $startAngle = atan2($vectorUY, $vectorUX);
        $magnitude          = sqrt(($vectorUX * $vectorUX + $vectorUY * $vectorUY) * ($vectorVX * $vectorVX + $vectorVY * $vectorVY));
        $cosDA      = $magnitude > 1e-10 ? max(-1.0, min(1.0, ($vectorUX * $vectorVX + $vectorUY * $vectorVY) / $magnitude)) : 0.0;
        $deltaAngle     = acos($cosDA);
        if ($vectorUX * $vectorVY - $vectorUY * $vectorVX < 0.0) {
            $deltaAngle = -$deltaAngle;
        }
        if ($params->sweep === 0 && $deltaAngle > 0.0) {
            $deltaAngle -= 2.0 * M_PI;
        } elseif ($params->sweep === 1 && $deltaAngle < 0.0) {
            $deltaAngle += 2.0 * M_PI;
        }

        return [$startAngle, $deltaAngle];
    }

    /**
     * Calculate transformed midpoint delta coordinates in the rotated arc space.
     *
     * @return array{0:float,1:float} [$px, $py]
     */
    private function calculatePrimeCoordinates(ArcParams $params): array
    {
        $deltaX2 = ($params->fromX - $params->toX) / 2.0;
        $deltaY2 = ($params->fromY - $params->toY) / 2.0;
        $primeX  =  $params->cosTh * $deltaX2 + $params->sinTh * $deltaY2;
        $primeY  = -$params->sinTh * $deltaX2 + $params->cosTh * $deltaY2;

        return [$primeX, $primeY];
    }

    /**
     * Generate cubic Bézier curve segments for the arc.
     *
     * Splits the arc into multiple segments and computes the Bézier control
     * points for each segment to approximate the circular/elliptical arc.
     *
     * @param float $cx Center X coordinate
     * @param float $cy Center Y coordinate
     * @param float $rx X-axis radius
     * @param float $ry Y-axis radius
     * @param float $cosTh Cosine of rotation angle
     * @param float $sinTh Sine of rotation angle
     * @param float $startAngle Starting angle in radians
     * @param float $dAngle Total angle delta in radians
     * @return array<int, array<int, float>> Array of Bézier curve control points
     */
    private function generateArcCurves(
        float $centerX,
        float $centerY,
        float $radiusX,
        float $radiusY,
        float $cosTh,
        float $sinTh,
        float $startAngle,
        float $deltaAngle,
    ): array {
        $segments = max(1, (int) ceil(abs($deltaAngle) / (M_PI / 2.0)));
        $angleStep       = $deltaAngle / $segments;
        $tanHalfAngleStep   = tan($angleStep / 2.0);
        $alpha    = abs($angleStep) > 1e-10
            ? sin($angleStep) * (sqrt(4.0 + 3.0 * $tanHalfAngleStep * $tanHalfAngleStep) - 1.0) / 3.0
            : 0.0;

        $curves = [];
        $angle1 = $startAngle;
        $cos1   = cos($angle1);
        $sin1   = sin($angle1);
        $endX1    = $centerX + $cosTh * $radiusX * $cos1 - $sinTh * $radiusY * $sin1;
        $endY1    = $centerY + $sinTh * $radiusX * $cos1 + $cosTh * $radiusY * $sin1;

        for ($i = 0; $i < $segments; $i++) {
            $angle2 = $angle1 + $angleStep;
            $cos2   = cos($angle2);
            $sin2   = sin($angle2);

            $endX2  = $centerX + $cosTh * $radiusX * $cos2 - $sinTh * $radiusY * $sin2;
            $endY2  = $centerY + $sinTh * $radiusX * $cos2 + $cosTh * $radiusY * $sin2;
            $tangentXD1 = -$cosTh * $radiusX * $sin1 - $sinTh * $radiusY * $cos1;
            $tangentYD1 = -$sinTh * $radiusX * $sin1 + $cosTh * $radiusY * $cos1;
            $tangentXD2 = -$cosTh * $radiusX * $sin2 - $sinTh * $radiusY * $cos2;
            $tangentYD2 = -$sinTh * $radiusX * $sin2 + $cosTh * $radiusY * $cos2;

            $curves[] = [
                $endX1 + $alpha * $tangentXD1,
                $endY1 + $alpha * $tangentYD1,
                $endX2 - $alpha * $tangentXD2,
                $endY2 - $alpha * $tangentYD2,
                $endX2,
                $endY2,
            ];

            $angle1 = $angle2;
            $cos1   = $cos2;
            $sin1   = $sin2;
            $endX1    = $endX2;
            $endY1    = $endY2;
        }

        return $curves;
    }
}
