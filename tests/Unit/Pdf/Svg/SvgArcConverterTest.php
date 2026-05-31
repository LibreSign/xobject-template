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

    #[DataProvider('provideSamePointScenarios')]
    public function testArcToBezierCurvesReturnsEmptyArrayForSamePointWithinTolerance(
        float $fromX,
        float $fromY,
        float $toX,
        float $toY,
    ): void {
        $converter = new SvgArcConverter();

        self::assertSame([], $converter->arcToBezierCurves($fromX, $fromY, 5.0, 6.0, 30.0, 0, 1, $toX, $toY));
    }

    #[DataProvider('provideBoundaryPointDeltaScenarios')]
    public function testArcToBezierCurvesDoesNotTreatExactToleranceAsSamePoint(float $toX, float $toY): void
    {
        $converter = new SvgArcConverter();

        $curves = $converter->arcToBezierCurves(10.0, 10.0, 5.0, 6.0, 30.0, 0, 1, $toX, $toY);

        self::assertNotSame([], $curves);
    }

    #[DataProvider('provideDegenerateRadiusScenarios')]
    public function testArcToBezierCurvesFallsBackToDegenerateLineWhenAnyRadiusIsTooSmall(
        float $radiusX,
        float $radiusY,
    ): void {
        $converter = new SvgArcConverter();

        self::assertSame(
            [[20.0, 30.0, 20.0, 30.0, 20.0, 30.0]],
            $converter->arcToBezierCurves(0.0, 0.0, $radiusX, $radiusY, 0.0, 0, 1, 20.0, 30.0),
        );
    }

    #[DataProvider('provideNonDegenerateRadiusBoundaryScenarios')]
    public function testArcToBezierCurvesDoesNotDegenerateAtOrAboveRadiusTolerance(float $radiusX, float $radiusY): void
    {
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

    #[DataProvider('provideExpectedCurveSamples')]
    public function testArcToBezierCurvesMatchesExpectedSamples(
        array $input,
        int $expectedSegmentCount,
        array $firstCurveExpected,
        array $lastCurveExpected,
    ): void {
        $converter = new SvgArcConverter();

        $curves = $converter->arcToBezierCurves(
            $input['fromX'],
            $input['fromY'],
            $input['radiusX'],
            $input['radiusY'],
            $input['rotation'],
            $input['largeArc'],
            $input['sweep'],
            $input['toX'],
            $input['toY'],
        );

        self::assertCount($expectedSegmentCount, $curves);
        self::assertCurveMatches($firstCurveExpected, $curves[0]);
        self::assertCurveMatches($lastCurveExpected, $curves[array_key_last($curves)]);
    }

    #[DataProvider('provideSegmentCountAndEndpointScenarios')]
    public function testArcToBezierCurvesGeneratesExpectedSegmentsAndEndsAtTarget(
        array $input,
        int $expectedSegmentCount,
    ): void {
        $converter = new SvgArcConverter();

        $curves = $converter->arcToBezierCurves(
            $input['fromX'],
            $input['fromY'],
            $input['radiusX'],
            $input['radiusY'],
            $input['rotation'],
            $input['largeArc'],
            $input['sweep'],
            $input['toX'],
            $input['toY'],
        );

        self::assertCount($expectedSegmentCount, $curves);
        foreach ($curves as $curve) {
            self::assertCount(6, $curve);
        }

        $lastCurve = $curves[array_key_last($curves)];
        self::assertEqualsWithDelta($input['toX'], $lastCurve[4], 0.0001);
        self::assertEqualsWithDelta($input['toY'], $lastCurve[5], 0.0001);
    }

    /**
     * @return iterable<string, array{fromX: float, fromY: float, toX: float, toY: float}>
     */
    public static function provideSamePointScenarios(): iterable
    {
        yield 'exact same point' => [
            'fromX' => 10.0,
            'fromY' => 10.0,
            'toX' => 10.0,
            'toY' => 10.0,
        ];

        yield 'both deltas below tolerance' => [
            'fromX' => 10.0,
            'fromY' => 10.0,
            'toX' => 10.0 + 5.0e-11,
            'toY' => 10.0 - 5.0e-11,
        ];
    }

    /**
     * @return iterable<string, array{toX: float, toY: float}>
     */
    public static function provideBoundaryPointDeltaScenarios(): iterable
    {
        yield 'single axis delta remains different point' => [
            'toX' => 10.0,
            'toY' => 14.0,
        ];

        yield 'x delta exactly at tolerance' => [
            'toX' => 10.0 + 1.0e-10,
            'toY' => 10.0,
        ];

        yield 'y delta exactly at tolerance' => [
            'toX' => 10.0,
            'toY' => 10.0 + 1.0e-10,
        ];
    }

    /**
     * @return iterable<string, array{radiusX: float, radiusY: float}>
     */
    public static function provideDegenerateRadiusScenarios(): iterable
    {
        yield 'radiusX zero' => [
            'radiusX' => 0.0,
            'radiusY' => 5.0,
        ];

        yield 'radiusY tiny below tolerance' => [
            'radiusX' => 5.0,
            'radiusY' => 1.0e-12,
        ];
    }

    /**
     * @return iterable<string, array{radiusX: float, radiusY: float}>
     */
    public static function provideNonDegenerateRadiusBoundaryScenarios(): iterable
    {
        yield 'radii just above tolerance' => [
            'radiusX' => 1.1e-10,
            'radiusY' => 2.2e-10,
        ];

        yield 'radiusX exactly at tolerance' => [
            'radiusX' => 1.0e-10,
            'radiusY' => 2.0e-10,
        ];

        yield 'radiusY exactly at tolerance' => [
            'radiusX' => 2.0e-10,
            'radiusY' => 1.0e-10,
        ];
    }

    /**
     * @return iterable<string, array{
     *     input: array{
     *         fromX: float,
     *         fromY: float,
     *         radiusX: float,
     *         radiusY: float,
     *         rotation: float,
     *         largeArc: int,
     *         sweep: int,
     *         toX: float,
     *         toY: float
     *     },
     *     expectedSegmentCount: int,
     *     firstCurveExpected: array<int, float>,
     *     lastCurveExpected: array<int, float>
     * }>
     */
    public static function provideExpectedCurveSamples(): iterable
    {
        yield 'half ellipse sample' => [
            'input' => [
                'fromX' => 0.0,
                'fromY' => 5.0,
                'radiusX' => 10.0,
                'radiusY' => 5.0,
                'rotation' => 0.0,
                'largeArc' => 0,
                'sweep' => 1,
                'toX' => 20.0,
                'toY' => 5.0,
            ],
            'expectedSegmentCount' => 2,
            'firstCurveExpected' => [
                -6.7182135842927015E-16,
                2.257081148225684,
                4.514162296451365,
                5.038660188219526E-16,
                9.999999999999998,
                0.0,
            ],
            'lastCurveExpected' => [
                15.485837703548633,
                -5.038660188219526E-16,
                20.0,
                2.2570811482256823,
                20.0,
                4.999999999999999,
            ],
        ];

        yield 'normalized circle sample' => [
            'input' => [
                'fromX' => 0.0,
                'fromY' => 0.0,
                'radiusX' => 5.0,
                'radiusY' => 5.0,
                'rotation' => 0.0,
                'largeArc' => 0,
                'sweep' => 1,
                'toX' => 30.0,
                'toY' => 0.0,
            ],
            'expectedSegmentCount' => 2,
            'firstCurveExpected' => [
                -1.0077320376439053E-15,
                -8.22875655532295,
                6.7712434446770455,
                -14.999999999999998,
                14.999999999999996,
                -15.0,
            ],
            'lastCurveExpected' => [
                23.228756555322946,
                -15.000000000000002,
                29.999999999999996,
                -8.228756555322954,
                30.0,
                -3.67394039744206E-15,
            ],
        ];

        yield 'quarter-arc flag variant sample' => [
            'input' => [
                'fromX' => 10.0,
                'fromY' => 0.0,
                'radiusX' => 10.0,
                'radiusY' => 10.0,
                'rotation' => 0.0,
                'largeArc' => 1,
                'sweep' => 1,
                'toX' => 0.0,
                'toY' => 10.0,
            ],
            'expectedSegmentCount' => 2,
            'firstCurveExpected' => [
                20.95014085253355,
                6.808005228802601,
                20.95014085253355,
                13.191994771197399,
                17.071067811865476,
                17.071067811865476,
            ],
            'lastCurveExpected' => [
                13.191994771197399,
                20.95014085253355,
                3.8790730406680765,
                13.879073040668077,
                0.0,
                10.0,
            ],
        ];

        yield 'asymmetric rotated normalized sample' => [
            'input' => [
                'fromX' => 0.0,
                'fromY' => 10.0,
                'radiusX' => 15.0,
                'radiusY' => 8.0,
                'rotation' => 45.0,
                'largeArc' => 1,
                'sweep' => 1,
                'toX' => 60.0,
                'toY' => 30.0,
            ],
            'expectedSegmentCount' => 2,
            'firstCurveExpected' => [
                -4.434385553963552,
                -6.137506191228175,
                5.459153479092354,
                -14.902504650171249,
                21.916666589738256,
                -9.416666946622618,
            ],
            'lastCurveExpected' => [
                38.37417970038416,
                -3.930829243073987,
                55.56561444603643,
                13.862493808771795,
                59.999999923071584,
                29.99999972004403,
            ],
        ];
    }

    /**
     * @return iterable<string, array{
     *     input: array{
     *         fromX: float,
     *         fromY: float,
     *         radiusX: float,
     *         radiusY: float,
     *         rotation: float,
     *         largeArc: int,
     *         sweep: int,
     *         toX: float,
     *         toY: float
     *     },
     *     expectedSegmentCount: int
     * }>
     */
    public static function provideSegmentCountAndEndpointScenarios(): iterable
    {
        yield 'quarter-like path with large-arc sweep yields two segments' => [
            'input' => [
                'fromX' => 10.0,
                'fromY' => 0.0,
                'radiusX' => 10.0,
                'radiusY' => 10.0,
                'rotation' => 0.0,
                'largeArc' => 1,
                'sweep' => 1,
                'toX' => 0.0,
                'toY' => 10.0,
            ],
            'expectedSegmentCount' => 2,
        ];

        yield 'clockwise path with opposite sweep still produces two segments' => [
            'input' => [
                'fromX' => 10.0,
                'fromY' => 0.0,
                'radiusX' => 10.0,
                'radiusY' => 10.0,
                'rotation' => 0.0,
                'largeArc' => 0,
                'sweep' => 0,
                'toX' => 0.0,
                'toY' => 10.0,
            ],
            'expectedSegmentCount' => 2,
        ];

        yield 'asymmetric rotated arc remains finite and reaches endpoint' => [
            'input' => [
                'fromX' => 0.0,
                'fromY' => 0.0,
                'radiusX' => 40.0,
                'radiusY' => 20.0,
                'rotation' => 45.0,
                'largeArc' => 1,
                'sweep' => 1,
                'toX' => 60.0,
                'toY' => 0.0,
            ],
            'expectedSegmentCount' => 2,
        ];
    }
}
