<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf\Svg;

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

    #[DataProvider('provideBoundaryArcBehaviorScenarios')]
    public function testArcToBezierCurvesMatchesBoundaryBehaviorThroughPublicApi(
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
        array $expectedFirstCurve,
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
        self::assertCurveMatches($expectedFirstCurve, $curves[0], 1.0E-12);
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
     *     expectedFirstCurve: array<int, float>,
     * }>
     */
    public static function provideBoundaryArcBehaviorScenarios(): iterable
    {
        yield 'near-zero span uses denominator floor guard path' => [
            'fromX' => 2.0E-6,
            'fromY' => 2.0E-6,
            'radiusX' => 1.0,
            'radiusY' => 1.0,
            'rotation' => 0.0,
            'largeArc' => 0,
            'sweep' => 1,
            'toX' => 0.0,
            'toY' => 0.0,
            'expectedSegmentCount' => 1,
            'expectedFirstCurve' => [
                0.31920047711974003,
                1.0950150852533551,
                0.3879073040668076,
                0.38790730406680757,
                0.0,
                0.0,
            ],
        ];

        yield 'tiny horizontal arc keeps one segment for sweep one' => [
            'fromX' => 0.0,
            'fromY' => 0.0,
            'radiusX' => 1.0,
            'radiusY' => 1.0,
            'rotation' => 0.0,
            'largeArc' => 0,
            'sweep' => 1,
            'toX' => 1.5491933384829667E-5,
            'toY' => 0.0,
            'expectedSegmentCount' => 1,
            'expectedFirstCurve' => [
                -0.9999922540333077,
                -0.5485837703548633,
                -0.5485682784214786,
                1.0077320376439051E-16,
                1.5491933384829667E-5,
                0.0,
            ],
        ];

        yield 'tiny horizontal arc with reverse sweep expands to three segments' => [
            'fromX' => 0.0,
            'fromY' => 0.0,
            'radiusX' => 1.0,
            'radiusY' => 1.0,
            'rotation' => 0.0,
            'largeArc' => 0,
            'sweep' => 0,
            'toX' => 1.5491933384829667E-5,
            'toY' => 0.0,
            'expectedSegmentCount' => 3,
            'expectedFirstCurve' => [
                -0.9999922540333075,
                0.5485837703548635,
                -0.5485760243881709,
                1.0,
                7.745966692476065E-6,
                1.0,
            ],
        ];
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
