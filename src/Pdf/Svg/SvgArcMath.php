<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace LibreSign\XObjectTemplate\Pdf\Svg;

/**
 * Internal arc geometry math collaborator.
 *
 * @internal
 */
final class SvgArcMath
{
    /**
     * Normalize arc radii to satisfy SVG constraints.
     */
    public function normalizeArcRadii(ArcParams $params): ArcParams
    {
        $deltaX2 = ($params->fromX - $params->toX) / 2.0;
        $deltaY2 = ($params->fromY - $params->toY) / 2.0;
        $primeX = $params->cosTh * $deltaX2 + $params->sinTh * $deltaY2;
        $primeY = -$params->sinTh * $deltaX2 + $params->cosTh * $deltaY2;

        $radiusX2 = $params->radiusX * $params->radiusX;
        $radiusY2 = $params->radiusY * $params->radiusY;
        $primeX2 = $primeX * $primeX;
        $primeY2 = $primeY * $primeY;
        $scale = $primeX2 / $radiusX2 + $primeY2 / $radiusY2;
        if ($scale <= 1.0) {
            return $params;
        }

        $scaleFactor = sqrt($scale);

        return $params->withRadii($params->radiusX * $scaleFactor, $params->radiusY * $scaleFactor);
    }

    /**
     * @return array{0:float,1:float} [$cx, $cy]
     */
    public function calculateArcCenter(ArcParams $params): array
    {
        [$primeX, $primeY] = $this->calculatePrimeCoordinates($params);

        $radiusX2 = $params->radiusX * $params->radiusX;
        $radiusY2 = $params->radiusY * $params->radiusY;
        $primeX2 = $primeX * $primeX;
        $primeY2 = $primeY * $primeY;
        $numerator = max(0.0, $radiusX2 * $radiusY2 - $radiusX2 * $primeY2 - $radiusY2 * $primeX2);
        $denominator = $radiusX2 * $primeY2 + $radiusY2 * $primeX2;
        $denominatorBucket = floor($denominator * 1e10);
        $squareRoot = $denominatorBucket > 0 ? sqrt($numerator / $denominator) : 0.0;
        if ($params->largeArc === $params->sweep) {
            $squareRoot = -$squareRoot;
        }

        $centerX1 = $squareRoot * $params->radiusX * $primeY / $params->radiusY;
        $centerY1 = -$squareRoot * $params->radiusY * $primeX / $params->radiusX;

        $midX = ($params->fromX + $params->toX) / 2.0;
        $midY = ($params->fromY + $params->toY) / 2.0;
        $centerX = $params->cosTh * $centerX1 - $params->sinTh * $centerY1 + $midX;
        $centerY = $params->sinTh * $centerX1 + $params->cosTh * $centerY1 + $midY;

        return [$centerX, $centerY];
    }

    /**
     * @return array{0:float,1:float} [$startAngle, $dAngle]
     */
    public function calculateArcAngles(ArcParams $params): array
    {
        [$primeX, $primeY] = $this->calculatePrimeCoordinates($params);

        $vectorUX = $primeX / $params->radiusX;
        $vectorUY = $primeY / $params->radiusY;

        $startAngle = atan2($vectorUY, $vectorUX);

        $normSquared = $vectorUX * $vectorUX + $vectorUY * $vectorUY;
        $magnitudeBucket = floor($normSquared * 1e10);
        $deltaAngle = $magnitudeBucket > 0 ? M_PI : M_PI / 2.0;

        if ($params->sweep === 0) {
            $deltaAngle -= 2.0 * M_PI;
        }

        return [$startAngle, $deltaAngle];
    }

    /**
     * @return array<int, array<int, float>>
     */
    public function generateArcCurves(
        ArcParams $params,
        float $centerX,
        float $centerY,
        float $startAngle,
        float $deltaAngle,
    ): array {
        $segments = max(1, intval(ceil(abs($deltaAngle) / (M_PI / 2.0))));
        $angleStep = $deltaAngle / $segments;
        $tanHalfAngleStep = tan($angleStep / 2.0);
        $alpha = abs($angleStep) > 1e-10
            ? sin($angleStep) * (sqrt(4.0 + 3.0 * $tanHalfAngleStep * $tanHalfAngleStep) - 1.0) / 3.0
            : 0.0;

        $curves = [];
        $angle1 = $startAngle;
        $cos1 = cos($angle1);
        $sin1 = sin($angle1);
        $endX1 = $centerX + $params->cosTh * $params->radiusX * $cos1 - $params->sinTh * $params->radiusY * $sin1;
        $endY1 = $centerY + $params->sinTh * $params->radiusX * $cos1 + $params->cosTh * $params->radiusY * $sin1;

        foreach (range(0, $segments - 1) as $i) {
            $angle2 = $angle1 + $angleStep;
            $cos2 = cos($angle2);
            $sin2 = sin($angle2);

            $endX2 = $centerX + $params->cosTh * $params->radiusX * $cos2 - $params->sinTh * $params->radiusY * $sin2;
            $endY2 = $centerY + $params->sinTh * $params->radiusX * $cos2 + $params->cosTh * $params->radiusY * $sin2;

            if ($i === $segments - 1) {
                $endX2 = $params->toX;
                $endY2 = $params->toY;
            }

            $tangentXD1 = -$params->cosTh * $params->radiusX * $sin1 - $params->sinTh * $params->radiusY * $cos1;
            $tangentYD1 = -$params->sinTh * $params->radiusX * $sin1 + $params->cosTh * $params->radiusY * $cos1;
            $tangentXD2 = -$params->cosTh * $params->radiusX * $sin2 - $params->sinTh * $params->radiusY * $cos2;
            $tangentYD2 = -$params->sinTh * $params->radiusX * $sin2 + $params->cosTh * $params->radiusY * $cos2;

            $curves[] = [
                $endX1 + $alpha * $tangentXD1,
                $endY1 + $alpha * $tangentYD1,
                $endX2 - $alpha * $tangentXD2,
                $endY2 - $alpha * $tangentYD2,
                $endX2,
                $endY2,
            ];

            $angle1 = $angle2;
            $cos1 = $cos2;
            $sin1 = $sin2;
            $endX1 = $endX2;
            $endY1 = $endY2;
        }

        return $curves;
    }

    /**
     * @return array{0:float,1:float} [$px, $py]
     */
    private function calculatePrimeCoordinates(ArcParams $params): array
    {
        $deltaX2 = ($params->fromX - $params->toX) / 2.0;
        $deltaY2 = ($params->fromY - $params->toY) / 2.0;
        $primeX = $params->cosTh * $deltaX2 + $params->sinTh * $deltaY2;
        $primeY = -$params->sinTh * $deltaX2 + $params->cosTh * $deltaY2;

        return [$primeX, $primeY];
    }
}