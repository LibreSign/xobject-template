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

    public function testArcToBezierCurvesDoesNotTreatSingleAxisDeltaAsSamePoint(): void
    {
        $converter = new SvgArcConverter();

        $curves = $converter->arcToBezierCurves(10.0, 10.0, 5.0, 6.0, 30.0, 0, 1, 10.0, 14.0);

        self::assertNotSame([], $curves);
    }

    public function testArcToBezierCurvesDoesNotTreatExactToleranceDeltaAsSamePoint(): void
    {
        $converter = new SvgArcConverter();

        $curves = $converter->arcToBezierCurves(
            10.0,
            10.0,
            5.0,
            6.0,
            30.0,
            0,
            1,
            10.0 + 1.0e-10,
            10.0,
        );

        self::assertNotSame([], $curves);
    }

    public function testArcToBezierCurvesFallsBackToDegenerateLineWhenAnyRadiusIsZero(): void
    {
        $converter = new SvgArcConverter();

        self::assertSame(
            [[20.0, 30.0, 20.0, 30.0, 20.0, 30.0]],
            $converter->arcToBezierCurves(0.0, 0.0, 0.0, 5.0, 0.0, 0, 1, 20.0, 30.0),
        );
    }

    public function testArcToBezierCurvesFallsBackToDegenerateLineWhenRadiusYIsTiny(): void
    {
        $converter = new SvgArcConverter();

        self::assertSame(
            [[20.0, 30.0, 20.0, 30.0, 20.0, 30.0]],
            $converter->arcToBezierCurves(0.0, 0.0, 5.0, 1.0e-12, 0.0, 0, 1, 20.0, 30.0),
        );
    }

    public function testArcToBezierCurvesDoesNotDegenerateWhenRadiiAreJustAboveTolerance(): void
    {
        $converter = new SvgArcConverter();

        $curves = $converter->arcToBezierCurves(0.0, 0.0, 1.1e-10, 2.2e-10, 0.0, 0, 1, 20.0, 30.0);

        self::assertNotSame([], $curves);
        self::assertNotSame([[20.0, 30.0, 20.0, 30.0, 20.0, 30.0]], $curves);
    }

    public function testArcToBezierCurvesDoesNotDegenerateAtExactRadiusTolerance(): void
    {
        $converter = new SvgArcConverter();

        $curves = $converter->arcToBezierCurves(
            0.0,
            0.0,
            1.0e-10,
            2.0e-10,
            0.0,
            0,
            1,
            20.0,
            30.0,
        );

        self::assertNotSame([], $curves);
        self::assertNotSame([[20.0, 30.0, 20.0, 30.0, 20.0, 30.0]], $curves);
    }

    public function testArcToBezierCurvesDoesNotDegenerateAtExactRadiusYTolerance(): void
    {
        $converter = new SvgArcConverter();

        $curves = $converter->arcToBezierCurves(
            0.0,
            0.0,
            2.0e-10,
            1.0e-10,
            0.0,
            0,
            1,
            20.0,
            30.0,
        );

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

    public function testCalculateArcCenterRespectsSweepDirection(): void
    {
        $converter = new SvgArcConverter();
        $counterClockwise = new ArcParams(10.0, 0.0, 0.0, 10.0, 10.0, 10.0, 1.0, 0.0, 0, 0);
        $clockwise = new ArcParams(10.0, 0.0, 0.0, 10.0, 10.0, 10.0, 1.0, 0.0, 0, 1);
        $degenerate = new ArcParams(0.0, 0.0, 0.0, 0.0, 10.0, 20.0, cos(deg2rad(30.0)), sin(deg2rad(30.0)), 0, 1);

        self::assertSame(
            [10.0, 10.0],
            self::invokePrivateMethod($converter, 'calculateArcCenter', [$counterClockwise]),
        );
        self::assertSame(
            [0.0, 0.0],
            self::invokePrivateMethod($converter, 'calculateArcCenter', [$clockwise]),
        );
        self::assertSame(
            [0.0, 0.0],
            self::invokePrivateMethod($converter, 'calculateArcCenter', [$degenerate]),
        );
    }

    public function testCalculateArcAnglesReturnsExpectedSweepAdjustedDelta(): void
    {
        $converter = new SvgArcConverter();
        $counterClockwise = new ArcParams(10.0, 0.0, 0.0, 10.0, 10.0, 10.0, 1.0, 0.0, 0, 0);
        $clockwise = new ArcParams(10.0, 0.0, 0.0, 10.0, 10.0, 10.0, 1.0, 0.0, 0, 1);

        $counterClockwiseAngles = self::invokePrivateMethod(
            $converter,
            'calculateArcAngles',
            [$counterClockwise, 10.0, 10.0],
        );
        $clockwiseAngles = self::invokePrivateMethod(
            $converter,
            'calculateArcAngles',
            [$clockwise, 0.0, 0.0],
        );

        self::assertEqualsWithDelta(-0.7853981633974483, $counterClockwiseAngles[0], 0.0001);
        self::assertEqualsWithDelta(-M_PI, $counterClockwiseAngles[1], 0.0001);
        self::assertEqualsWithDelta(-0.7853981633974483, $clockwiseAngles[0], 0.0001);
        self::assertEqualsWithDelta(M_PI, $clockwiseAngles[1], 0.0001);
    }

    public function testGenerateArcCurvesUsesSingleSegmentBelowNinetyDegrees(): void
    {
        $converter = new SvgArcConverter();

        $curves = self::invokePrivateMethod(
            $converter,
            'generateArcCurves',
            [0.0, 0.0, 10.0, 10.0, 1.0, 0.0, 0.0, M_PI / 4.0],
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

    public function testGenerateArcCurvesSplitsLargerAnglesIntoExpectedSegments(): void
    {
        $converter = new SvgArcConverter();

        $curves = self::invokePrivateMethod(
            $converter,
            'generateArcCurves',
            [0.0, 0.0, 10.0, 10.0, 1.0, 0.0, 0.0, 3.0 * M_PI / 4.0],
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
    }
}
