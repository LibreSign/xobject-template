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
        float $rx,
        float $ry,
        float $rotation,
        int $largeArc,
        int $sweep,
        float $toX,
        float $toY,
    ): array {
        if (abs($toX - $fromX) < 1e-10 && abs($toY - $fromY) < 1e-10) {
            return [];
        }

        if ($rx < 1e-10 || $ry < 1e-10) {
            return [[$toX, $toY, $toX, $toY, $toX, $toY]];
        }

        $th    = deg2rad($rotation);
        $cosTh = cos($th);
        $sinTh = sin($th);

        // Step 1: Normalize radii and compute half-distances
        [$rx, $ry] = $this->normalizeArcRadii(
            $fromX,
            $fromY,
            $toX,
            $toY,
            $rx,
            $ry,
            $cosTh,
            $sinTh,
        );

        // Step 2: Calculate center point
        [$cx, $cy] = $this->calculateArcCenter(
            $fromX,
            $fromY,
            $toX,
            $toY,
            $rx,
            $ry,
            $cosTh,
            $sinTh,
            $largeArc,
            $sweep,
        );

        // Step 3: Calculate angles and deltas
        [$startAngle, $dAngle] = $this->calculateArcAngles(
            $fromX,
            $fromY,
            $toX,
            $toY,
            $cx,
            $cy,
            $rx,
            $ry,
            $cosTh,
            $sinTh,
            $largeArc,
            $sweep,
        );

        // Step 4: Generate cubic Bézier curves
        return $this->generateArcCurves($cx, $cy, $rx, $ry, $cosTh, $sinTh, $startAngle, $dAngle);
    }

    /**
     * Normalize arc radii to satisfy SVG constraints.
     *
     * If the given radii are too small, they are scaled up to ensure the arc
     * can connect the start and end points.
     *
     * @param float $fromX Starting X coordinate
     * @param float $fromY Starting Y coordinate
     * @param float $toX Ending X coordinate
     * @param float $toY Ending Y coordinate
     * @param float $rx X-axis radius
     * @param float $ry Y-axis radius
     * @param float $cosTh Cosine of rotation angle
     * @param float $sinTh Sine of rotation angle
     * @return array<int, float> [$rx, $ry] Normalized radii
     */
    private function normalizeArcRadii(
        float $fromX,
        float $fromY,
        float $toX,
        float $toY,
        float $rx,
        float $ry,
        float $cosTh,
        float $sinTh,
    ): array {
        $dx2 = ($fromX - $toX) / 2.0;
        $dy2 = ($fromY - $toY) / 2.0;
        $px  =  $cosTh * $dx2 + $sinTh * $dy2;
        $py  = -$sinTh * $dx2 + $cosTh * $dy2;

        $rx2   = $rx * $rx;
        $ry2   = $ry * $ry;
        $px2   = $px * $px;
        $py2   = $py * $py;
        $scale = $px2 / $rx2 + $py2 / $ry2;
        if ($scale > 1.0) {
            $s   = sqrt($scale);
            $rx *= $s;
            $ry *= $s;
        }

        return [$rx, $ry];
    }

    /**
     * Calculate the center point of the arc.
     *
     * Given the start point, end point, radii, and arc flags, compute the
     * center point of the ellipse that defines the arc.
     *
     * @param float $fromX Starting X coordinate
     * @param float $fromY Starting Y coordinate
     * @param float $toX Ending X coordinate
     * @param float $toY Ending Y coordinate
     * @param float $rx X-axis radius
     * @param float $ry Y-axis radius
     * @param float $cosTh Cosine of rotation angle
     * @param float $sinTh Sine of rotation angle
     * @param int $largeArc Large arc flag (0 or 1)
     * @param int $sweep Sweep flag (0 or 1)
     * @return array<int, float> [$cx, $cy] Center coordinates
     */
    private function calculateArcCenter(
        float $fromX,
        float $fromY,
        float $toX,
        float $toY,
        float $rx,
        float $ry,
        float $cosTh,
        float $sinTh,
        int $largeArc,
        int $sweep,
    ): array {
        $dx2 = ($fromX - $toX) / 2.0;
        $dy2 = ($fromY - $toY) / 2.0;
        $px  =  $cosTh * $dx2 + $sinTh * $dy2;
        $py  = -$sinTh * $dx2 + $cosTh * $dy2;

        $rx2   = $rx * $rx;
        $ry2   = $ry * $ry;
        $px2   = $px * $px;
        $py2   = $py * $py;
        $num   = max(0.0, $rx2 * $ry2 - $rx2 * $py2 - $ry2 * $px2);
        $den   = $rx2 * $py2 + $ry2 * $px2;
        $sq    = $den > 1e-10 ? sqrt($num / $den) : 0.0;
        if ($largeArc === $sweep) {
            $sq = -$sq;
        }

        $cx1 =  $sq * $rx * $py / $ry;
        $cy1 = -$sq * $ry * $px / $rx;

        $midX = ($fromX + $toX) / 2.0;
        $midY = ($fromY + $toY) / 2.0;
        $cx   = $cosTh * $cx1 - $sinTh * $cy1 + $midX;
        $cy   = $sinTh * $cx1 + $cosTh * $cy1 + $midY;

        return [$cx, $cy];
    }

    /**
     * Calculate start angle and total angle delta for the arc.
     *
     * Determines the angular span of the arc in the ellipse coordinate space,
     * accounting for the large-arc and sweep flags.
     *
     * @param float $fromX Starting X coordinate
     * @param float $fromY Starting Y coordinate
     * @param float $toX Ending X coordinate
     * @param float $toY Ending Y coordinate
     * @param float $cx Center X coordinate
     * @param float $cy Center Y coordinate
     * @param float $rx X-axis radius
     * @param float $ry Y-axis radius
     * @param float $cosTh Cosine of rotation angle
     * @param float $sinTh Sine of rotation angle
     * @param int $largeArc Large arc flag (0 or 1)
     * @param int $sweep Sweep flag (0 or 1)
     * @return array<int, float> [$startAngle, $dAngle] Start and delta angles in radians
     */
    private function calculateArcAngles(
        float $fromX,
        float $fromY,
        float $toX,
        float $toY,
        float $cx,
        float $cy,
        float $rx,
        float $ry,
        float $cosTh,
        float $sinTh,
        int $largeArc,
        int $sweep,
    ): array {
        $dx2 = ($fromX - $toX) / 2.0;
        $dy2 = ($fromY - $toY) / 2.0;
        $px  =  $cosTh * $dx2 + $sinTh * $dy2;
        $py  = -$sinTh * $dx2 + $cosTh * $dy2;

        $ux = ($px - 0.0) / $rx;
        $uy = ($py - 0.0) / $ry;
        $vx = (-$px - 0.0) / $rx;
        $vy = (-$py - 0.0) / $ry;

        $startAngle = atan2($uy, $ux);
        $n          = sqrt(($ux * $ux + $uy * $uy) * ($vx * $vx + $vy * $vy));
        $cosDA      = $n > 1e-10 ? max(-1.0, min(1.0, ($ux * $vx + $uy * $vy) / $n)) : 0.0;
        $dAngle     = acos($cosDA);
        if ($ux * $vy - $uy * $vx < 0.0) {
            $dAngle = -$dAngle;
        }
        if ($sweep === 0 && $dAngle > 0.0) {
            $dAngle -= 2.0 * M_PI;
        } elseif ($sweep === 1 && $dAngle < 0.0) {
            $dAngle += 2.0 * M_PI;
        }

        return [$startAngle, $dAngle];
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
        float $cx,
        float $cy,
        float $rx,
        float $ry,
        float $cosTh,
        float $sinTh,
        float $startAngle,
        float $dAngle,
    ): array {
        $segments = max(1, (int) ceil(abs($dAngle) / (M_PI / 2.0)));
        $da       = $dAngle / $segments;
        $tanDA2   = tan($da / 2.0);
        $alpha    = abs($da) > 1e-10
            ? sin($da) * (sqrt(4.0 + 3.0 * $tanDA2 * $tanDA2) - 1.0) / 3.0
            : 0.0;

        $curves = [];
        $angle1 = $startAngle;
        $cos1   = cos($angle1);
        $sin1   = sin($angle1);
        $ex1    = $cx + $cosTh * $rx * $cos1 - $sinTh * $ry * $sin1;
        $ey1    = $cy + $sinTh * $rx * $cos1 + $cosTh * $ry * $sin1;

        for ($i = 0; $i < $segments; $i++) {
            $angle2 = $angle1 + $da;
            $cos2   = cos($angle2);
            $sin2   = sin($angle2);

            $ex2  = $cx + $cosTh * $rx * $cos2 - $sinTh * $ry * $sin2;
            $ey2  = $cy + $sinTh * $rx * $cos2 + $cosTh * $ry * $sin2;
            $txd1 = -$cosTh * $rx * $sin1 - $sinTh * $ry * $cos1;
            $tyd1 = -$sinTh * $rx * $sin1 + $cosTh * $ry * $cos1;
            $txd2 = -$cosTh * $rx * $sin2 - $sinTh * $ry * $cos2;
            $tyd2 = -$sinTh * $rx * $sin2 + $cosTh * $ry * $cos2;

            $curves[] = [
                $ex1 + $alpha * $txd1,
                $ey1 + $alpha * $tyd1,
                $ex2 - $alpha * $txd2,
                $ey2 - $alpha * $tyd2,
                $ex2,
                $ey2,
            ];

            $angle1 = $angle2;
            $cos1   = $cos2;
            $sin1   = $sin2;
            $ex1    = $ex2;
            $ey1    = $ey2;
        }

        return $curves;
    }
}
