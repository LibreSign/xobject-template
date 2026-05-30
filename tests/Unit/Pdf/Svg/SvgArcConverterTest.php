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
    public function testArcToBezierCurvesReturnsEmptyArrayWhenStartAndEndPointsMatch(): void
    {
        $converter = new SvgArcConverter();

        self::assertSame([], $converter->arcToBezierCurves(10.0, 10.0, 5.0, 6.0, 30.0, 0, 1, 10.0, 10.0));
    }

    public function testArcToBezierCurvesFallsBackToDegenerateLineWhenAnyRadiusIsZero(): void
    {
        $converter = new SvgArcConverter();

        self::assertSame(
            [[20.0, 30.0, 20.0, 30.0, 20.0, 30.0]],
            $converter->arcToBezierCurves(0.0, 0.0, 0.0, 5.0, 0.0, 0, 1, 20.0, 30.0),
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
     * @return iterable<string, array{fromX: float, fromY: float, radiusX: float, radiusY: float, rotation: float, largeArc: int, sweep: int, toX: float, toY: float, expectedSegmentCount: int}>
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
