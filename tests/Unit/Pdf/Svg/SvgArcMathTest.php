<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf\Svg;

use LibreSign\XObjectTemplate\Pdf\Svg\ArcParams;
use LibreSign\XObjectTemplate\Pdf\Svg\SvgArcMath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SvgArcMathTest extends TestCase
{
    /**
     * @param array<int, float> $expected
     * @param array<int, float> $actual
     */
    private static function assertCurveMatches(array $expected, array $actual, float $delta = 0.0001): void
    {
        self::assertCount(count($expected), $actual);

        foreach ($expected as $index => $expectedValue) {
            self::assertEqualsWithDelta(
                $expectedValue,
                $actual[$index],
                $delta,
                sprintf('Curve index %d differs.', $index),
            );
        }
    }

    private static function createCurveGenerationParams(
        float $centerX,
        float $centerY,
        float $radiusX,
        float $radiusY,
        float $cosTh,
        float $sinTh,
        float $startAngle,
        float $deltaAngle,
    ): ArcParams {
        $endAngle = $startAngle + $deltaAngle;
        $endCos = cos($endAngle);
        $endSin = sin($endAngle);

        return new ArcParams(
            0.0,
            0.0,
            $centerX + $cosTh * $radiusX * $endCos - $sinTh * $radiusY * $endSin,
            $centerY + $sinTh * $radiusX * $endCos + $cosTh * $radiusY * $endSin,
            $radiusX,
            $radiusY,
            $cosTh,
            $sinTh,
            0,
            1,
        );
    }

    public function testNormalizeArcRadiiReturnsSameInstanceWhenScaleIsExactlyOne(): void
    {
        $math = new SvgArcMath();
        $params = new ArcParams(
            0.0,
            0.0,
            2.0,
            0.0,
            1.0,
            1.0,
            1.0,
            0.0,
            0,
            1,
        );

        $normalized = $math->normalizeArcRadii($params);

        self::assertSame($params, $normalized);
    }

    public function testNormalizeArcRadiiMatchesExpectedScaleForRotatedArc(): void
    {
        $math = new SvgArcMath();

        $cosTh = cos(deg2rad(45.0));
        $sinTh = sin(deg2rad(45.0));
        $params = new ArcParams(
            0.0,
            0.0,
            6.0,
            2.0,
            1.0,
            2.0,
            $cosTh,
            $sinTh,
            0,
            1,
        );

        $normalized = $math->normalizeArcRadii($params);

        self::assertNotSame($params, $normalized);

        $deltaX2 = ($params->fromX - $params->toX) / 2.0;
        $deltaY2 = ($params->fromY - $params->toY) / 2.0;
        $primeX = $params->cosTh * $deltaX2 + $params->sinTh * $deltaY2;
        $primeY = -$params->sinTh * $deltaX2 + $params->cosTh * $deltaY2;
        $radiusX2 = $params->radiusX * $params->radiusX;
        $radiusY2 = $params->radiusY * $params->radiusY;
        $scale = ($primeX * $primeX) / $radiusX2 + ($primeY * $primeY) / $radiusY2;
        $scaleFactor = sqrt($scale);

        self::assertGreaterThan(1.0, $scale);
        self::assertEqualsWithDelta($params->radiusX * $scaleFactor, $normalized->radiusX, 1.0E-12);
        self::assertEqualsWithDelta($params->radiusY * $scaleFactor, $normalized->radiusY, 1.0E-12);
    }

    #[DataProvider('provideCalculateArcCenterScenarios')]
    public function testCalculateArcCenterBoundaryScenarios(
        ArcParams $params,
        float $expectedCenterX,
        float $expectedCenterY,
    ): void {
        $math = new SvgArcMath();

        [$centerX, $centerY] = $math->calculateArcCenter($params);

        self::assertEqualsWithDelta($expectedCenterX, $centerX, 1.0E-15);
        self::assertEqualsWithDelta($expectedCenterY, $centerY, 1.0E-15);
    }

    /**
     * @return iterable<string, array{params: ArcParams, expectedCenterX: float, expectedCenterY: float}>
     */
    public static function provideCalculateArcCenterScenarios(): iterable
    {
        yield 'denominator bucket zero uses midpoint' => [
            'params' => new ArcParams(
                2.0E-6,
                2.0E-6,
                0.0,
                0.0,
                1.0,
                1.0,
                1.0,
                0.0,
                0,
                1,
            ),
            'expectedCenterX' => 1.0E-6,
            'expectedCenterY' => 1.0E-6,
        ];

        yield 'floor versus round boundary keeps midpoint branch' => [
            'params' => new ArcParams(
                0.0,
                0.0,
                1.5491933384829667E-5,
                0.0,
                1.0,
                1.0,
                1.0,
                0.0,
                0,
                1,
            ),
            'expectedCenterX' => 7.745966692414834E-6,
            'expectedCenterY' => 0.0,
        ];
    }

    #[DataProvider('provideCalculateArcAnglesScenarios')]
    public function testCalculateArcAnglesBoundaryScenarios(
        ArcParams $params,
        float $expectedStartAngle,
        float $expectedDeltaAngle,
    ): void {
        $math = new SvgArcMath();

        [$startAngle, $deltaAngle] = $math->calculateArcAngles($params);

        self::assertEqualsWithDelta($expectedStartAngle, $startAngle, 1.0E-12);
        self::assertEqualsWithDelta($expectedDeltaAngle, $deltaAngle, 1.0E-12);
    }

    /**
     * @return iterable<string, array{params: ArcParams, expectedStartAngle: float, expectedDeltaAngle: float}>
     */
    public static function provideCalculateArcAnglesScenarios(): iterable
    {
        yield 'near-zero magnitude with sweep adjustment' => [
            'params' => new ArcParams(
                0.0,
                0.0,
                0.0,
                0.0,
                1.0,
                1.0,
                1.0,
                0.0,
                0,
                0,
            ),
            'expectedStartAngle' => 0.0,
            'expectedDeltaAngle' => -(3.0 * M_PI / 2.0),
        ];

        yield 'stable magnitude uses pi branch' => [
            'params' => new ArcParams(
                2.0,
                0.0,
                0.0,
                0.0,
                1.0,
                1.0,
                1.0,
                0.0,
                0,
                1,
            ),
            'expectedStartAngle' => 0.0,
            'expectedDeltaAngle' => M_PI,
        ];

        yield 'rounding boundary keeps half-pi branch' => [
            'params' => new ArcParams(
                0.0,
                0.0,
                1.5491933384829667E-5,
                0.0,
                1.0,
                1.0,
                1.0,
                0.0,
                0,
                1,
            ),
            'expectedStartAngle' => M_PI,
            'expectedDeltaAngle' => M_PI / 2.0,
        ];
    }

    #[DataProvider('provideGenerateArcCurvesScenarios')]
    public function testGenerateArcCurvesBoundaryScenarios(
        ArcParams $params,
        float $centerX,
        float $centerY,
        float $startAngle,
        float $deltaAngle,
        int $expectedCurveCount,
        array $expectedFirstCurve,
    ): void {
        $math = new SvgArcMath();

        $curves = $math->generateArcCurves($params, $centerX, $centerY, $startAngle, $deltaAngle);

        self::assertCount($expectedCurveCount, $curves);
        self::assertCurveMatches($expectedFirstCurve, $curves[0], 1.0E-12);
    }

    /**
     * @return iterable<string, array{
     *     params: ArcParams,
     *     centerX: float,
     *     centerY: float,
     *     startAngle: float,
     *     deltaAngle: float,
     *     expectedCurveCount: int,
     *     expectedFirstCurve: array<int, float>,
     * }>
     */
    public static function provideGenerateArcCurvesScenarios(): iterable
    {
        $ceilDeltaAngle = 1.1 * (M_PI / 2.0);
        $zeroDeltaAngle = 0.0;
        $thresholdDeltaAngle = 1.0E-10;
        $alphaDeltaAngle = M_PI / 3.0;

        $alphaTanHalfAngleStep = tan($alphaDeltaAngle / 2.0);
        $alpha = sin($alphaDeltaAngle)
            * (sqrt(4.0 + 3.0 * $alphaTanHalfAngleStep * $alphaTanHalfAngleStep) - 1.0)
            / 3.0;

        yield 'ceil boundary keeps two segments' => [
            'params' => self::createCurveGenerationParams(0.0, 0.0, 10.0, 7.0, 1.0, 0.0, 0.0, $ceilDeltaAngle),
            'centerX' => 0.0,
            'centerY' => 0.0,
            'startAngle' => 0.0,
            'deltaAngle' => $ceilDeltaAngle,
            'expectedCurveCount' => 2,
            'expectedFirstCurve' => [
                10.0,
                2.046640160464945,
                8.71773389395062,
                3.993655301352086,
                6.494480483301835,
                5.322841759200218,
            ],
        ];

        yield 'zero delta still emits one curve' => [
            'params' => self::createCurveGenerationParams(0.0, 0.0, 10.0, 7.0, 1.0, 0.0, 0.0, $zeroDeltaAngle),
            'centerX' => 0.0,
            'centerY' => 0.0,
            'startAngle' => 0.0,
            'deltaAngle' => $zeroDeltaAngle,
            'expectedCurveCount' => 1,
            'expectedFirstCurve' => [10.0, 0.0, 10.0, 0.0, 10.0, 0.0],
        ];

        yield 'threshold angle keeps alpha zero' => [
            'params' => self::createCurveGenerationParams(0.0, 0.0, 9.0, 4.0, 1.0, 0.0, 0.0, $thresholdDeltaAngle),
            'centerX' => 0.0,
            'centerY' => 0.0,
            'startAngle' => 0.0,
            'deltaAngle' => $thresholdDeltaAngle,
            'expectedCurveCount' => 1,
            'expectedFirstCurve' => [
                9.0,
                0.0,
                9.0,
                4.0E-10,
                9.0,
                4.0E-10,
            ],
        ];

        yield 'alpha formula keeps squared tan term' => [
            'params' => self::createCurveGenerationParams(0.0, 0.0, 12.0, 8.0, 1.0, 0.0, 0.0, $alphaDeltaAngle),
            'centerX' => 0.0,
            'centerY' => 0.0,
            'startAngle' => 0.0,
            'deltaAngle' => $alphaDeltaAngle,
            'expectedCurveCount' => 1,
            'expectedFirstCurve' => [
                12.0,
                $alpha * 8.0,
                9.708203932499371,
                5.500914871183149,
                6.000000000000002,
                6.928203230275509,
            ],
        ];
    }
}
