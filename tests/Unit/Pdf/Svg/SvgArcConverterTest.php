<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf\Svg;

use LibreSign\XObjectTemplate\Pdf\Svg\ArcParams;
use LibreSign\XObjectTemplate\Pdf\Svg\SvgArcConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SvgArcConverterTest extends TestCase
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

    /**
     * @param list<mixed> $arguments
     */
    private static function invokePrivateMethod(SvgArcConverter $converter, string $methodName, array $arguments): mixed
    {
        $reflection = new \ReflectionClass($converter);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($converter, $arguments);
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

    public function testArcToBezierCurvesReturnsEmptyArrayWhenStartAndEndPointsMatch(): void
    {
        $converter = new SvgArcConverter();

        self::assertSame([], $converter->arcToBezierCurves(10.0, 10.0, 5.0, 6.0, 30.0, 0, 1, 10.0, 10.0));
    }

    public function testArcToBezierCurvesReturnsEmptyArrayWhenBothAxisDeltasStayBelowTolerance(): void
    {
        $converter = new SvgArcConverter();

        self::assertSame(
            [],
            $converter->arcToBezierCurves(10.0, 10.0, 5.0, 6.0, 30.0, 0, 1, 10.0 + 5.0e-11, 10.0 - 5.0e-11),
        );
    }

    #[DataProvider('provideNotSamePointScenarios')]
    public function testArcToBezierCurvesDoesNotTreatBoundaryDeltasAsSamePoint(
        float $fromX,
        float $fromY,
        float $toX,
        float $toY,
        float $radiusX,
        float $radiusY,
        float $rotation,
    ): void {
        $converter = new SvgArcConverter();

        $curves = $converter->arcToBezierCurves($fromX, $fromY, $radiusX, $radiusY, $rotation, 0, 1, $toX, $toY);

        self::assertNotSame([], $curves);
    }

    #[DataProvider('provideDegenerateRadiusFallbackScenarios')]
    public function testArcToBezierCurvesFallsBackToDegenerateLineForTinyRadius(
        float $fromX,
        float $fromY,
        float $radiusX,
        float $radiusY,
        float $toX,
        float $toY,
    ): void {
        $converter = new SvgArcConverter();

        self::assertSame(
            [[$toX, $toY, $toX, $toY, $toX, $toY]],
            $converter->arcToBezierCurves($fromX, $fromY, $radiusX, $radiusY, 0.0, 0, 1, $toX, $toY),
        );
    }

    #[DataProvider('provideNonDegenerateRadiusBoundaryScenarios')]
    public function testArcToBezierCurvesDoesNotDegenerateAtBoundaryRadii(
        float $radiusX,
        float $radiusY,
    ): void {
        $converter = new SvgArcConverter();

        $curves = $converter->arcToBezierCurves(0.0, 0.0, $radiusX, $radiusY, 0.0, 0, 1, 20.0, 30.0);

        self::assertNotSame([], $curves);
        self::assertNotSame([[20.0, 30.0, 20.0, 30.0, 20.0, 30.0]], $curves);
    }

    public function testArcToBezierCurvesUsesSweepAndLargeArcFlagsToChooseDifferentSolutions(): void
    {
        $converter = new SvgArcConverter();

        $smallSweep = $converter->arcToBezierCurves(10.0, 0.0, 10.0, 10.0, 0.0, 0, 1, 0.0, 10.0);
        $smallReverseSweep = $converter->arcToBezierCurves(10.0, 0.0, 10.0, 10.0, 0.0, 0, 0, 0.0, 10.0);
        $largeSweep = $converter->arcToBezierCurves(10.0, 0.0, 10.0, 10.0, 0.0, 1, 1, 0.0, 10.0);

        self::assertNotSame([], $smallSweep);
        self::assertNotSame([], $smallReverseSweep);
        self::assertNotSame([], $largeSweep);
        self::assertNotEquals($smallSweep, $smallReverseSweep);
        self::assertNotEquals($smallSweep, $largeSweep);
    }

    public function testArcToBezierCurvesReturnsFiniteControlPointsForNormalizedRotatedArc(): void
    {
        $converter = new SvgArcConverter();

        $curves = $converter->arcToBezierCurves(0.0, 0.0, 4.0, 3.0, 35.0, 1, 0, 25.0, 8.0);

        self::assertNotSame([], $curves);

        foreach ($curves as $curve) {
            foreach ($curve as $value) {
                self::assertTrue(is_finite($value));
            }
        }
    }

    public function testArcToBezierCurvesMatchesExpectedHalfEllipseControlPoints(): void
    {
        $converter = new SvgArcConverter();

        $curves = $converter->arcToBezierCurves(0.0, 5.0, 10.0, 5.0, 0.0, 0, 1, 20.0, 5.0);

        self::assertCount(2, $curves);
        self::assertCurveMatches(
            [
                -6.7182135842927015E-16,
                2.257081148225684,
                4.514162296451365,
                5.038660188219526E-16,
                9.999999999999998,
                0.0,
            ],
            $curves[0],
        );
        self::assertCurveMatches(
            [
                15.485837703548633,
                -5.038660188219526E-16,
                20.0,
                2.2570811482256823,
                20.0,
                4.999999999999999,
            ],
            $curves[1],
        );
    }

    public function testArcToBezierCurvesMatchesExpectedNormalizedArcControlPoints(): void
    {
        $converter = new SvgArcConverter();

        $curves = $converter->arcToBezierCurves(0.0, 0.0, 5.0, 5.0, 0.0, 0, 1, 30.0, 0.0);

        self::assertCount(2, $curves);
        self::assertCurveMatches(
            [
                -1.0077320376439053E-15,
                -8.22875655532295,
                6.7712434446770455,
                -14.999999999999998,
                14.999999999999996,
                -15.0,
            ],
            $curves[0],
        );
        self::assertCurveMatches(
            [
                23.228756555322946,
                -15.000000000000002,
                29.999999999999996,
                -8.228756555322954,
                30.0,
                -3.67394039744206E-15,
            ],
            $curves[1],
        );
    }

    public function testArcToBezierCurvesMatchesExpectedQuarterArcSolutionsForFlagVariants(): void
    {
        $converter = new SvgArcConverter();

        $largeSweep = $converter->arcToBezierCurves(10.0, 0.0, 10.0, 10.0, 0.0, 1, 1, 0.0, 10.0);
        $smallSweep = $converter->arcToBezierCurves(10.0, 0.0, 10.0, 10.0, 0.0, 0, 1, 0.0, 10.0);

        self::assertCount(2, $largeSweep);
        self::assertCount(2, $smallSweep);
        self::assertCurveMatches(
            [
                20.95014085253355,
                6.808005228802601,
                20.95014085253355,
                13.191994771197399,
                17.071067811865476,
                17.071067811865476,
            ],
            $largeSweep[0],
        );
        self::assertCurveMatches(
            [
                10.95014085253355,
                -3.191994771197398,
                10.95014085253355,
                3.191994771197398,
                7.0710678118654755,
                7.071067811865475,
            ],
            $smallSweep[0],
        );
    }

    public function testCalculatePrimeCoordinatesMatchesExpectedRotatedValues(): void
    {
        $converter = new SvgArcConverter();
        $params = new ArcParams(
            0.0,
            0.0,
            60.0,
            0.0,
            40.0,
            20.0,
            cos(deg2rad(45.0)),
            sin(deg2rad(45.0)),
            1,
            1,
        );

        $primeCoordinates = self::invokePrivateMethod($converter, 'calculatePrimeCoordinates', [$params]);

        self::assertSame([-21.213203435596427, 21.213203435596423], $primeCoordinates);
    }

    public function testNormalizeArcRadiiReturnsSameInstanceWhenScaleEqualsOne(): void
    {
        $converter = new SvgArcConverter();
        $params = new ArcParams(
            0.0,
            0.0,
            20.0,
            0.0,
            10.0,
            99.0,
            1.0,
            0.0,
            0,
            1,
        );

        $normalized = self::invokePrivateMethod($converter, 'normalizeArcRadii', [$params]);

        self::assertSame($params, $normalized);
    }

    public function testNormalizeArcRadiiScalesRotatedArcUsingExpectedFactors(): void
    {
        $converter = new SvgArcConverter();
        $params = new ArcParams(
            0.0,
            10.0,
            60.0,
            30.0,
            15.0,
            8.0,
            cos(deg2rad(45.0)),
            sin(deg2rad(45.0)),
            1,
            1,
        );

        $normalized = self::invokePrivateMethod($converter, 'normalizeArcRadii', [$params]);

        self::assertNotSame($params, $normalized);
        self::assertEqualsWithDelta(38.77015604817706, $normalized->radiusX, 0.0001);
        self::assertEqualsWithDelta(20.677416559027762, $normalized->radiusY, 0.0001);
    }

    #[DataProvider('provideArcCenterSweepDirectionScenarios')]
    public function testCalculateArcCenterRespectsSweepDirection(
        ArcParams $params,
        float $expectedCenterX,
        float $expectedCenterY,
    ): void {
        $converter = new SvgArcConverter();

        $center = self::invokePrivateMethod($converter, 'calculateArcCenter', [$params]);

        self::assertSame([$expectedCenterX, $expectedCenterY], $center);
    }

    public function testCalculateArcCenterMatchesExpectedRotatedNormalizedCenter(): void
    {
        $converter = new SvgArcConverter();
        $params = new ArcParams(
            0.0,
            10.0,
            60.0,
            30.0,
            38.77015604817706,
            20.677416559027762,
            cos(deg2rad(45.0)),
            sin(deg2rad(45.0)),
            1,
            1,
        );

        $center = self::invokePrivateMethod($converter, 'calculateArcCenter', [$params]);

        self::assertEqualsWithDelta(29.999999923071595, $center[0], 0.0001);
        self::assertEqualsWithDelta(19.99999972004405, $center[1], 0.0001);
    }

    #[DataProvider('provideArcCenterMidpointFallbackScenarios')]
    public function testCalculateArcCenterFallsBackToMidpointForSmallDenominator(
        ArcParams $params,
        float $expectedCenterX,
        float $expectedCenterY,
        float $delta,
    ): void {
        $converter = new SvgArcConverter();

        $center = self::invokePrivateMethod($converter, 'calculateArcCenter', [$params]);

        self::assertEqualsWithDelta($expectedCenterX, $center[0], $delta);
        self::assertEqualsWithDelta($expectedCenterY, $center[1], $delta);
    }

    #[DataProvider('provideArcAngleSweepScenarios')]
    public function testCalculateArcAnglesReturnsExpectedSweepAdjustedDelta(
        ArcParams $params,
        float $centerX,
        float $centerY,
        float $expectedStartAngle,
        float $expectedDeltaAngle,
    ): void {
        $converter = new SvgArcConverter();

        $angles = self::invokePrivateMethod(
            $converter,
            'calculateArcAngles',
            [$params, $centerX, $centerY],
        );

        self::assertEqualsWithDelta($expectedStartAngle, $angles[0], 0.0001);
        self::assertEqualsWithDelta($expectedDeltaAngle, $angles[1], 0.0001);
    }

    public function testCalculateArcAnglesMatchesExpectedRotatedNormalizedValues(): void
    {
        $converter = new SvgArcConverter();
        $params = new ArcParams(
            0.0,
            10.0,
            60.0,
            30.0,
            38.77015604817706,
            20.677416559027762,
            cos(deg2rad(45.0)),
            sin(deg2rad(45.0)),
            1,
            1,
        );

        $angles = self::invokePrivateMethod(
            $converter,
            'calculateArcAngles',
            [$params, 29.999999923071595, 19.99999972004405],
        );

        self::assertEqualsWithDelta(2.388441372627599, $angles[0], 0.0001);
        self::assertEqualsWithDelta(M_PI, $angles[1], 0.0001);
    }

    #[DataProvider('provideArcAngleFallbackCosineScenarios')]
    public function testCalculateArcAnglesUsesFallbackCosineForSmallMagnitudes(ArcParams $params): void
    {
        $converter = new SvgArcConverter();

        $angles = self::invokePrivateMethod($converter, 'calculateArcAngles', [$params, 0.0, 0.0]);

        self::assertEqualsWithDelta(0.0, $angles[0], 1.0e-12);
        self::assertEqualsWithDelta(M_PI / 2.0, $angles[1], 1.0e-12);
    }

    public function testCalculateArcAnglesKeepsPiForLargeMagnitudeAsExpectedByOppositeVectors(): void
    {
        $converter = new SvgArcConverter();
        $params = new ArcParams(
            2.0,
            1.0,
            -2.0,
            -1.0,
            1.0,
            1.0,
            1.0,
            0.0,
            0,
            1,
        );

        $angles = self::invokePrivateMethod($converter, 'calculateArcAngles', [$params, 0.0, 0.0]);

        self::assertEqualsWithDelta(atan2(1.0, 2.0), $angles[0], 1.0e-12);
        self::assertEqualsWithDelta(M_PI, $angles[1], 1.0e-12);
    }

    public function testCalculateArcCenterAndAnglesMatchExpectedAsymmetricValues(): void
    {
        $converter = new SvgArcConverter();
        $params = new ArcParams(
            0.0,
            0.0,
            25.0,
            8.0,
            13.566066509534181,
            10.174549882150636,
            cos(deg2rad(35.0)),
            sin(deg2rad(35.0)),
            1,
            0,
        );

        $center = self::invokePrivateMethod($converter, 'calculateArcCenter', [$params]);
        $angles = self::invokePrivateMethod(
            $converter,
            'calculateArcAngles',
            [$params, $center[0], $center[1]],
        );

        self::assertEqualsWithDelta(12.499999988863545, $center[0], 0.0001);
        self::assertEqualsWithDelta(4.000000104332266, $center[1], 0.0001);
        self::assertEqualsWithDelta(2.7489504213408784, $angles[0], 0.0001);
        self::assertEqualsWithDelta(-M_PI, $angles[1], 0.0001);
    }

    public function testGenerateArcCurvesUsesSingleSegmentBelowNinetyDegrees(): void
    {
        $converter = new SvgArcConverter();

        $params = self::createCurveGenerationParams(0.0, 0.0, 10.0, 10.0, 1.0, 0.0, 0.0, M_PI / 4.0);
        $curves = self::invokePrivateMethod(
            $converter,
            'generateArcCurves',
            [$params, 0.0, 0.0, 0.0, M_PI / 4.0],
        );

        self::assertCount(1, $curves);
        self::assertCurveMatches(
            [
                10.0,
                2.6511477349130246,
                8.94571235314983,
                5.1964232705811195,
                7.0710678118654755,
                7.071067811865475,
            ],
            $curves[0],
        );
    }

    public function testGenerateArcCurvesReturnsSinglePointCurveWhenDeltaAngleIsZero(): void
    {
        $converter = new SvgArcConverter();

        $params = self::createCurveGenerationParams(0.0, 0.0, 10.0, 10.0, 1.0, 0.0, 0.0, 0.0);
        $curves = self::invokePrivateMethod(
            $converter,
            'generateArcCurves',
            [$params, 0.0, 0.0, 0.0, 0.0],
        );

        self::assertCount(1, $curves);
        self::assertCurveMatches([10.0, 0.0, 10.0, 0.0, 10.0, 0.0], $curves[0]);
    }

    #[DataProvider('provideRotatedQuarterSegmentScenarios')]
    public function testGenerateArcCurvesMatchesExpectedRotatedQuarterSegments(
        float $startAngle,
        float $deltaAngle,
        array $expectedCurve,
    ): void {
        $converter = new SvgArcConverter();
        $cosTh = cos(deg2rad(45.0));
        $sinTh = sin(deg2rad(45.0));
        $params = self::createCurveGenerationParams(3.0, -2.0, 10.0, 6.0, $cosTh, $sinTh, $startAngle, $deltaAngle);

        $curves = self::invokePrivateMethod(
            $converter,
            'generateArcCurves',
            [
                $params,
                3.0,
                -2.0,
                $startAngle,
                $deltaAngle,
            ],
        );

        self::assertCount(1, $curves);
        self::assertCurveMatches($expectedCurve, $curves[0]);
    }

    public function testGenerateArcCurvesSplitsLargerAnglesIntoExpectedSegments(): void
    {
        $converter = new SvgArcConverter();

        $params = self::createCurveGenerationParams(0.0, 0.0, 10.0, 10.0, 1.0, 0.0, 0.0, 3.0 * M_PI / 4.0);
        $curves = self::invokePrivateMethod(
            $converter,
            'generateArcCurves',
            [$params, 0.0, 0.0, 0.0, 3.0 * M_PI / 4.0],
        );

        self::assertCount(2, $curves);
        self::assertCurveMatches(
            [
                10.0,
                4.036465386317128,
                7.556042077759557,
                7.6941068964541515,
                3.8268343236508984,
                9.238795325112868,
            ],
            $curves[0],
        );
        self::assertCurveMatches(
            [
                0.09762656954223958,
                10.783483753771584,
                -4.216855765175856,
                9.925279858555093,
                -7.071067811865475,
                7.0710678118654755,
            ],
            $curves[1],
        );
    }

    public function testGenerateArcCurvesUsesCeilingWhenAngleSpanExceedsNinetyDegreesByFraction(): void
    {
        $converter = new SvgArcConverter();

        $deltaAngle = 0.6 * M_PI;
        $params = self::createCurveGenerationParams(0.0, 0.0, 10.0, 10.0, 1.0, 0.0, 0.0, $deltaAngle);
        $curves = self::invokePrivateMethod(
            $converter,
            'generateArcCurves',
            [$params, 0.0, 0.0, 0.0, $deltaAngle],
        );

        self::assertCount(2, $curves);
    }

    #[DataProvider('provideGenerateArcCurvesSegmentCountScenarios')]
    public function testGenerateArcCurvesUsesExpectedSegmentCountForAngleSpan(
        float $deltaAngle,
        int $expectedSegments,
    ): void {
        $converter = new SvgArcConverter();

        $params = self::createCurveGenerationParams(0.0, 0.0, 10.0, 10.0, 1.0, 0.0, 0.0, $deltaAngle);
        $curves = self::invokePrivateMethod(
            $converter,
            'generateArcCurves',
            [$params, 0.0, 0.0, 0.0, $deltaAngle],
        );

        self::assertCount($expectedSegments, $curves);
    }

    public function testGenerateArcCurvesUsesZeroAlphaWhenAngleStepEqualsTolerance(): void
    {
        $converter = new SvgArcConverter();

        $deltaAngle = 1.0e-10;
        $params = self::createCurveGenerationParams(0.0, 0.0, 10.0, 10.0, 1.0, 0.0, 0.0, $deltaAngle);
        $curves = self::invokePrivateMethod(
            $converter,
            'generateArcCurves',
            [$params, 0.0, 0.0, 0.0, $deltaAngle],
        );

        self::assertCount(1, $curves);
        self::assertEqualsWithDelta(10.0, $curves[0][0], 1.0e-15);
        self::assertEqualsWithDelta(0.0, $curves[0][1], 1.0e-15);
    }

    public function testGenerateArcCurvesMatchesExpectedRotatedThreeQuarterSegments(): void
    {
        $converter = new SvgArcConverter();
        $cosTh = cos(deg2rad(45.0));
        $sinTh = sin(deg2rad(45.0));
        $params = self::createCurveGenerationParams(3.0, -2.0, 10.0, 6.0, $cosTh, $sinTh, 0.0, 3.0 * M_PI / 4.0);

        $curves = self::invokePrivateMethod(
            $converter,
            'generateArcCurves',
            [
                $params,
                3.0,
                -2.0,
                0.0,
                3.0 * M_PI / 4.0,
            ],
        );

        self::assertCount(2, $curves);
        self::assertCurveMatches(
            [
                8.358540583851704,
                6.783595039879246,
                5.078595495120528,
                6.607261689108819,
                1.7862916061018566,
                4.625669395360115,
            ],
            $curves[0],
        );
        self::assertCurveMatches(
            [-1.506012282916814, 2.64407710161141, -4.192706922736574, -0.7708276909462963, -5.0, -3.9999999999999987],
            $curves[1],
        );
    }

    #[DataProvider('provideArcScenarios')]
    public function testArcToBezierCurvesGeneratesExpectedCurveShape(
        float $fromX,
        float $fromY,
        float $radiusX,
        float $radiusY,
        float $rotation,
        int $largeArc,
        int $sweep,
        float $toX,
        float $toY,
        int $expectedSegmentCount,
    ): void {
        $converter = new SvgArcConverter();

        $curves = $converter->arcToBezierCurves(
            $fromX,
            $fromY,
            $radiusX,
            $radiusY,
            $rotation,
            $largeArc,
            $sweep,
            $toX,
            $toY,
        );

        self::assertCount($expectedSegmentCount, $curves);

        foreach ($curves as $curve) {
            self::assertCount(6, $curve);
        }

        $lastCurve = $curves[array_key_last($curves)];
        self::assertEqualsWithDelta($toX, $lastCurve[4], 0.0001);
        self::assertEqualsWithDelta($toY, $lastCurve[5], 0.0001);
    }

    /**
    * @return iterable<string, array{
    *     fromX: float,
    *     fromY: float,
    *     radiusX: float,
    *     radiusY: float,
    *     rotation: float,
    *     largeArc: int,
    *     sweep: int,
    *     toX: float,
    *     toY: float,
    *     expectedSegmentCount: int,
    * }>
     */
    public static function provideArcScenarios(): iterable
    {
        yield 'symmetric half ellipse arc' => [
            'fromX' => 0.0,
            'fromY' => 5.0,
            'radiusX' => 10.0,
            'radiusY' => 5.0,
            'rotation' => 0.0,
            'largeArc' => 0,
            'sweep' => 1,
            'toX' => 20.0,
            'toY' => 5.0,
            'expectedSegmentCount' => 2,
        ];

        yield 'rotated arc uses multiple segments' => [
            'fromX' => 0.0,
            'fromY' => 0.0,
            'radiusX' => 40.0,
            'radiusY' => 20.0,
            'rotation' => 45.0,
            'largeArc' => 1,
            'sweep' => 1,
            'toX' => 60.0,
            'toY' => 0.0,
            'expectedSegmentCount' => 2,
        ];

        yield 'undersized radii are normalized to still produce a curve' => [
            'fromX' => 0.0,
            'fromY' => 0.0,
            'radiusX' => 5.0,
            'radiusY' => 5.0,
            'rotation' => 0.0,
            'largeArc' => 0,
            'sweep' => 1,
            'toX' => 30.0,
            'toY' => 0.0,
            'expectedSegmentCount' => 2,
        ];

        yield 'quarter-turn with reverse sweep still resolves to two segments' => [
            'fromX' => 10.0,
            'fromY' => 0.0,
            'radiusX' => 10.0,
            'radiusY' => 10.0,
            'rotation' => 0.0,
            'largeArc' => 0,
            'sweep' => 0,
            'toX' => 0.0,
            'toY' => 10.0,
            'expectedSegmentCount' => 2,
        ];

        yield 'quarter-turn with large arc flag uses two segments' => [
            'fromX' => 10.0,
            'fromY' => 0.0,
            'radiusX' => 10.0,
            'radiusY' => 10.0,
            'rotation' => 0.0,
            'largeArc' => 1,
            'sweep' => 1,
            'toX' => 0.0,
            'toY' => 10.0,
            'expectedSegmentCount' => 2,
        ];
    }

    /**
     * @return iterable<string, array{
     *     fromX: float,
     *     fromY: float,
     *     toX: float,
     *     toY: float,
     *     radiusX: float,
     *     radiusY: float,
     *     rotation: float,
     * }>
     */
    public static function provideNotSamePointScenarios(): iterable
    {
        yield 'single-axis delta on y' => [
            'fromX' => 10.0,
            'fromY' => 10.0,
            'toX' => 10.0,
            'toY' => 14.0,
            'radiusX' => 5.0,
            'radiusY' => 6.0,
            'rotation' => 30.0,
        ];

        yield 'exact tolerance on x' => [
            'fromX' => 10.0,
            'fromY' => 10.0,
            'toX' => 10.0 + 1.0e-10,
            'toY' => 10.0,
            'radiusX' => 5.0,
            'radiusY' => 6.0,
            'rotation' => 30.0,
        ];

        yield 'exact tolerance x and sub-tolerance y' => [
            'fromX' => 10.0,
            'fromY' => 10.0,
            'toX' => 10.0 + 1.0e-10,
            'toY' => 10.0 + 5.0e-11,
            'radiusX' => 5.0,
            'radiusY' => 6.0,
            'rotation' => 30.0,
        ];

        yield 'sub-tolerance x and exact tolerance y' => [
            'fromX' => 10.0,
            'fromY' => 10.0,
            'toX' => 10.0 + 5.0e-11,
            'toY' => 10.0 + 1.0e-10,
            'radiusX' => 5.0,
            'radiusY' => 6.0,
            'rotation' => 30.0,
        ];

        yield 'origin exact tolerance on x' => [
            'fromX' => 0.0,
            'fromY' => 0.0,
            'toX' => 1.0e-10,
            'toY' => 5.0e-11,
            'radiusX' => 5.0,
            'radiusY' => 6.0,
            'rotation' => 0.0,
        ];

        yield 'origin exact tolerance on y' => [
            'fromX' => 0.0,
            'fromY' => 0.0,
            'toX' => 5.0e-11,
            'toY' => 1.0e-10,
            'radiusX' => 5.0,
            'radiusY' => 6.0,
            'rotation' => 0.0,
        ];

        yield 'negative exact tolerance on x' => [
            'fromX' => 10.0,
            'fromY' => 10.0,
            'toX' => 10.0 - 1.0e-10,
            'toY' => 10.0,
            'radiusX' => 5.0,
            'radiusY' => 6.0,
            'rotation' => 30.0,
        ];

        yield 'negative exact tolerance on y' => [
            'fromX' => 10.0,
            'fromY' => 10.0,
            'toX' => 10.0,
            'toY' => 10.0 - 1.0e-10,
            'radiusX' => 5.0,
            'radiusY' => 6.0,
            'rotation' => 30.0,
        ];
    }

    /**
     * @return iterable<string, array{
     *     fromX: float,
     *     fromY: float,
     *     radiusX: float,
     *     radiusY: float,
     *     toX: float,
     *     toY: float,
     * }>
     */
    public static function provideDegenerateRadiusFallbackScenarios(): iterable
    {
        yield 'radius x is zero' => [
            'fromX' => 0.0,
            'fromY' => 0.0,
            'radiusX' => 0.0,
            'radiusY' => 5.0,
            'toX' => 20.0,
            'toY' => 30.0,
        ];

        yield 'radius y below tolerance' => [
            'fromX' => 0.0,
            'fromY' => 0.0,
            'radiusX' => 5.0,
            'radiusY' => 1.0e-12,
            'toX' => 20.0,
            'toY' => 30.0,
        ];

        yield 'radius x below tolerance' => [
            'fromX' => 0.0,
            'fromY' => 0.0,
            'radiusX' => 1.0e-12,
            'radiusY' => 5.0,
            'toX' => 20.0,
            'toY' => 30.0,
        ];

        yield 'both radii below tolerance' => [
            'fromX' => 0.0,
            'fromY' => 0.0,
            'radiusX' => 1.0e-12,
            'radiusY' => 1.0e-12,
            'toX' => 20.0,
            'toY' => 30.0,
        ];
    }

    /**
     * @return iterable<string, array{radiusX: float, radiusY: float}>
     */
    public static function provideNonDegenerateRadiusBoundaryScenarios(): iterable
    {
        yield 'both radii just above tolerance' => [
            'radiusX' => 1.1e-10,
            'radiusY' => 2.2e-10,
        ];

        yield 'x radius at exact tolerance' => [
            'radiusX' => 1.0e-10,
            'radiusY' => 2.0e-10,
        ];

        yield 'y radius at exact tolerance' => [
            'radiusX' => 2.0e-10,
            'radiusY' => 1.0e-10,
        ];

        yield 'both radii at exact tolerance' => [
            'radiusX' => 1.0e-10,
            'radiusY' => 1.0e-10,
        ];
    }

    /**
     * @return iterable<string, array{params: ArcParams, expectedCenterX: float, expectedCenterY: float}>
     */
    public static function provideArcCenterSweepDirectionScenarios(): iterable
    {
        yield 'counter-clockwise sweep center' => [
            'params' => new ArcParams(10.0, 0.0, 0.0, 10.0, 10.0, 10.0, 1.0, 0.0, 0, 0),
            'expectedCenterX' => 10.0,
            'expectedCenterY' => 10.0,
        ];

        yield 'clockwise sweep center' => [
            'params' => new ArcParams(10.0, 0.0, 0.0, 10.0, 10.0, 10.0, 1.0, 0.0, 0, 1),
            'expectedCenterX' => 0.0,
            'expectedCenterY' => 0.0,
        ];

        yield 'degenerate endpoints fall back to midpoint' => [
            'params' => new ArcParams(0.0, 0.0, 0.0, 0.0, 10.0, 20.0, cos(deg2rad(30.0)), sin(deg2rad(30.0)), 0, 1),
            'expectedCenterX' => 0.0,
            'expectedCenterY' => 0.0,
        ];
    }

    /**
     * @return iterable<string, array{params: ArcParams, expectedCenterX: float, expectedCenterY: float, delta: float}>
     */
    public static function provideArcCenterMidpointFallbackScenarios(): iterable
    {
        yield 'below denominator tolerance' => [
            'params' => new ArcParams(0.0, 0.0, -1.0e-6, 0.0, 1.0, 1.0, 1.0, 0.0, 0, 1),
            'expectedCenterX' => -5.0e-7,
            'expectedCenterY' => 0.0,
            'delta' => 1.0e-12,
        ];

        $primeX = sqrt(6.0e-11);

        yield 'denominator bucket rounds down' => [
            'params' => new ArcParams(0.0, 0.0, -2.0 * $primeX, 0.0, 1.0, 1.0, 1.0, 0.0, 0, 1),
            'expectedCenterX' => -$primeX,
            'expectedCenterY' => 0.0,
            'delta' => 1.0e-12,
        ];
    }

    /**
     * @return iterable<string, array{
     *     params: ArcParams,
     *     centerX: float,
     *     centerY: float,
     *     expectedStartAngle: float,
     *     expectedDeltaAngle: float,
     * }>
     */
    public static function provideArcAngleSweepScenarios(): iterable
    {
        yield 'counter-clockwise sweep adjusts to negative delta' => [
            'params' => new ArcParams(10.0, 0.0, 0.0, 10.0, 10.0, 10.0, 1.0, 0.0, 0, 0),
            'centerX' => 10.0,
            'centerY' => 10.0,
            'expectedStartAngle' => -0.7853981633974483,
            'expectedDeltaAngle' => -M_PI,
        ];

        yield 'clockwise sweep keeps positive delta' => [
            'params' => new ArcParams(10.0, 0.0, 0.0, 10.0, 10.0, 10.0, 1.0, 0.0, 0, 1),
            'centerX' => 0.0,
            'centerY' => 0.0,
            'expectedStartAngle' => -0.7853981633974483,
            'expectedDeltaAngle' => M_PI,
        ];
    }

    /**
     * @return iterable<string, array{params: ArcParams}>
     */
    public static function provideArcAngleFallbackCosineScenarios(): iterable
    {
        yield 'vector magnitude below tolerance' => [
            'params' => new ArcParams(0.0, 0.0, -2.0e-6, 0.0, 1.0, 1.0, 1.0, 0.0, 0, 1),
        ];

        $primeX = sqrt(6.0e-11);

        yield 'magnitude bucket rounds down' => [
            'params' => new ArcParams(0.0, 0.0, -2.0 * $primeX, 0.0, 1.0, 1.0, 1.0, 0.0, 0, 1),
        ];
    }

    /**
     * @return iterable<string, array{startAngle: float, deltaAngle: float, expectedCurve: list<float>}>
     */
    public static function provideRotatedQuarterSegmentScenarios(): iterable
    {
        yield 'zero start angle quarter segment' => [
            'startAngle' => 0.0,
            'deltaAngle' => M_PI / 4.0,
            'expectedCurve' => [8.946281087094862, 6.195854536636088, 7.120918187930419, 6.530229546982604, 5.0, 6.0],
        ];

        yield 'non-zero start angle quarter segment' => [
            'startAngle' => M_PI / 4.0,
            'deltaAngle' => M_PI / 4.0,
            'expectedCurve' => [
                2.8790818120695802,
                5.469770453017396,
                0.632003854165071,
                4.117285228403642,
                -1.2426406871192843,
                2.242640687119286,
            ],
        ];
    }

    /**
     * @return iterable<string, array{deltaAngle: float, expectedSegments: int}>
     */
    public static function provideGenerateArcCurvesSegmentCountScenarios(): iterable
    {
        yield 'zero angle keeps a single segment' => [
            'deltaAngle' => 0.0,
            'expectedSegments' => 1,
        ];

        yield 'exactly ninety degrees stays in one segment' => [
            'deltaAngle' => M_PI / 2.0,
            'expectedSegments' => 1,
        ];

        yield 'fraction above ninety degrees uses two segments' => [
            'deltaAngle' => 0.6 * M_PI,
            'expectedSegments' => 2,
        ];

        yield 'one hundred eighty degrees uses two segments' => [
            'deltaAngle' => M_PI,
            'expectedSegments' => 2,
        ];
    }
}
