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
     * @param array<int, mixed> $arguments
     */
    private static function invokePrivateMethod(object $object, string $methodName, array $arguments = []): mixed
    {
        $reflection = new \ReflectionClass($object);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $arguments);
    }

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
        $converter = new SvgArcConverter();
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

        $normalized = self::invokePrivateMethod($converter, 'normalizeArcRadii', [$params]);

        self::assertInstanceOf(ArcParams::class, $normalized);
        self::assertSame($params, $normalized);
    }

    public function testNormalizeArcRadiiMatchesExpectedScaleForRotatedArc(): void
    {
        $converter = new SvgArcConverter();

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

        $normalized = self::invokePrivateMethod($converter, 'normalizeArcRadii', [$params]);

        self::assertInstanceOf(ArcParams::class, $normalized);
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

    public function testCalculateArcCenterUsesZeroSquareRootWhenDenominatorBucketIsZero(): void
    {
        $converter = new SvgArcConverter();
        $params = new ArcParams(
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
        );

        [$centerX, $centerY] = self::invokePrivateMethod($converter, 'calculateArcCenter', [$params]);

        self::assertEqualsWithDelta(1.0E-6, $centerX, 1.0E-15);
        self::assertEqualsWithDelta(1.0E-6, $centerY, 1.0E-15);
    }

    public function testCalculateArcCenterKeepsMidpointWhenDenominatorBucketRoundsUpButFloorIsZero(): void
    {
        $converter = new SvgArcConverter();
        $params = new ArcParams(
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
        );

        [$centerX, $centerY] = self::invokePrivateMethod($converter, 'calculateArcCenter', [$params]);

        self::assertEqualsWithDelta(7.745966692414834E-6, $centerX, 1.0E-15);
        self::assertEqualsWithDelta(0.0, $centerY, 1.0E-15);
    }

    public function testCalculateArcAnglesUsesHalfPiBranchAndSweepAdjustmentForNearZeroMagnitude(): void
    {
        $converter = new SvgArcConverter();
        $params = new ArcParams(
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
        );

        [$startAngle, $deltaAngle] = self::invokePrivateMethod($converter, 'calculateArcAngles', [$params, 0.0, 0.0]);

        self::assertEqualsWithDelta(0.0, $startAngle, 1.0E-12);
        self::assertEqualsWithDelta(-(3.0 * M_PI / 2.0), $deltaAngle, 1.0E-12);
    }

    public function testCalculateArcAnglesUsesPiBranchForStableMagnitude(): void
    {
        $converter = new SvgArcConverter();
        $params = new ArcParams(
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
        );

        [$startAngle, $deltaAngle] = self::invokePrivateMethod($converter, 'calculateArcAngles', [$params, 0.0, 0.0]);

        self::assertEqualsWithDelta(0.0, $startAngle, 1.0E-12);
        self::assertEqualsWithDelta(M_PI, $deltaAngle, 1.0E-12);
    }

    public function testCalculateArcAnglesUsesHalfPiWhenMagnitudeBucketRoundsUpButFloorIsZero(): void
    {
        $converter = new SvgArcConverter();
        $params = new ArcParams(
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
        );

        [$startAngle, $deltaAngle] = self::invokePrivateMethod($converter, 'calculateArcAngles', [$params, 0.0, 0.0]);

        self::assertEqualsWithDelta(M_PI, $startAngle, 1.0E-12);
        self::assertEqualsWithDelta(M_PI / 2.0, $deltaAngle, 1.0E-12);
    }

    public function testGenerateArcCurvesUsesCeilToDetermineSegmentCount(): void
    {
        $converter = new SvgArcConverter();
        $deltaAngle = 1.1 * (M_PI / 2.0);
        $params = self::createCurveGenerationParams(0.0, 0.0, 10.0, 7.0, 1.0, 0.0, 0.0, $deltaAngle);

        $curves = self::invokePrivateMethod(
            $converter,
            'generateArcCurves',
            [$params, 0.0, 0.0, 0.0, $deltaAngle],
        );

        self::assertIsArray($curves);
        self::assertCount(2, $curves);
    }

    public function testGenerateArcCurvesStillReturnsSingleCurveForZeroDeltaAngle(): void
    {
        $converter = new SvgArcConverter();
        $deltaAngle = 0.0;
        $params = self::createCurveGenerationParams(0.0, 0.0, 10.0, 7.0, 1.0, 0.0, 0.0, $deltaAngle);

        $curves = self::invokePrivateMethod(
            $converter,
            'generateArcCurves',
            [$params, 0.0, 0.0, 0.0, $deltaAngle],
        );

        self::assertIsArray($curves);
        self::assertCount(1, $curves);
        self::assertCurveMatches([10.0, 0.0, 10.0, 0.0, 10.0, 0.0], $curves[0], 1.0E-12);
    }

    public function testGenerateArcCurvesKeepsAlphaAtZeroWhenAngleStepHitsThreshold(): void
    {
        $converter = new SvgArcConverter();
        $deltaAngle = 1.0E-10;
        $params = self::createCurveGenerationParams(0.0, 0.0, 9.0, 4.0, 1.0, 0.0, 0.0, $deltaAngle);

        $curves = self::invokePrivateMethod(
            $converter,
            'generateArcCurves',
            [$params, 0.0, 0.0, 0.0, $deltaAngle],
        );

        self::assertCount(1, $curves);
        self::assertEqualsWithDelta(9.0, $curves[0][0], 1.0E-12);
        self::assertEqualsWithDelta(0.0, $curves[0][1], 1.0E-12);
    }

    public function testGenerateArcCurvesUsesSquaredTanHalfStepTermInAlpha(): void
    {
        $converter = new SvgArcConverter();
        $deltaAngle = M_PI / 3.0;
        $radiusX = 12.0;
        $radiusY = 8.0;
        $params = self::createCurveGenerationParams(0.0, 0.0, $radiusX, $radiusY, 1.0, 0.0, 0.0, $deltaAngle);

        $curves = self::invokePrivateMethod(
            $converter,
            'generateArcCurves',
            [$params, 0.0, 0.0, 0.0, $deltaAngle],
        );

        self::assertCount(1, $curves);

        $tanHalfAngleStep = tan($deltaAngle / 2.0);
        $alpha = sin($deltaAngle) * (sqrt(4.0 + 3.0 * $tanHalfAngleStep * $tanHalfAngleStep) - 1.0) / 3.0;

        $expectedControlX1 = $radiusX;
        $expectedControlY1 = $alpha * $radiusY;

        self::assertEqualsWithDelta($expectedControlX1, $curves[0][0], 1.0E-12);
        self::assertEqualsWithDelta($expectedControlY1, $curves[0][1], 1.0E-12);
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
}
