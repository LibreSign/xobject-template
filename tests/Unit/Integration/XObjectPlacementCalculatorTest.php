<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Integration;

use InvalidArgumentException;
use LibreSign\XObjectTemplate\Dto\CompileResult;
use LibreSign\XObjectTemplate\Integration\XObjectPlacementCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class XObjectPlacementCalculatorTest extends TestCase
{
    /**
     * @return iterable<string, array{
     *     0: string,
     *     1: array{0: float, 1: float, 2: float, 3: float},
     *     2: float,
     *     3: float,
     *     4: float,
     *     5: array{scaleX: float, scaleY: float, width: float, height: float, translateX: float, translateY: float},
     *     6?: string,
     *     7?: string
     * }>
     */
    public static function placementProvider(): iterable
    {
        yield 'fromWidth compensates bbox origin and formats pdf command' => [
            'fromWidth',
            [12.5, 4.0, 252.5, 88.0],
            120.0,
            20.0,
            30.0,
            [
                'scaleX' => 0.5,
                'scaleY' => 0.5,
                'width' => 120.0,
                'height' => 42.0,
                'translateX' => 13.75,
                'translateY' => 28.0,
            ],
            'Fm0',
            'q 0.5 0 0 0.5 13.75 28 cm /Fm0 Do Q',
        ];

        yield 'fromHeight scales from bbox height with explicit coordinates' => [
            'fromHeight',
            [0.0, 0.0, 240.0, 84.0],
            168.0,
            15.0,
            25.0,
            [
                'scaleX' => 2.0,
                'scaleY' => 2.0,
                'width' => 480.0,
                'height' => 168.0,
                'translateX' => 15.0,
                'translateY' => 25.0,
            ],
        ];

        yield 'fromWidth defaults omitted coordinates to origin-compensated translation' => [
            'fromWidth',
            [10.0, 20.0, 110.0, 70.0],
            50.0,
            0.0,
            0.0,
            [
                'scaleX' => 0.5,
                'scaleY' => 0.5,
                'width' => 50.0,
                'height' => 25.0,
                'translateX' => -5.0,
                'translateY' => -10.0,
            ],
        ];

        yield 'fromHeight defaults omitted coordinates to origin-compensated translation' => [
            'fromHeight',
            [10.0, 20.0, 110.0, 70.0],
            25.0,
            0.0,
            0.0,
            [
                'scaleX' => 0.5,
                'scaleY' => 0.5,
                'width' => 50.0,
                'height' => 25.0,
                'translateX' => -5.0,
                'translateY' => -10.0,
            ],
        ];

        yield 'fromScale defaults omitted coordinates to origin-compensated translation' => [
            'fromScale',
            [10.0, 20.0, 110.0, 70.0],
            2.0,
            0.0,
            0.0,
            [
                'scaleX' => 2.0,
                'scaleY' => 2.0,
                'width' => 200.0,
                'height' => 100.0,
                'translateX' => -20.0,
                'translateY' => -40.0,
            ],
        ];

        yield 'fromScale repositions negative bbox origins into user space' => [
            'fromScale',
            [-10.0, -5.0, 90.0, 45.0],
            1.5,
            2.0,
            3.0,
            [
                'scaleX' => 1.5,
                'scaleY' => 1.5,
                'width' => 150.0,
                'height' => 75.0,
                'translateX' => 17.0,
                'translateY' => 10.5,
            ],
            ' /Fm7 ',
            'q 1.5 0 0 1.5 17 10.5 cm /Fm7 Do Q',
        ];
    }

    /**
     * @return iterable<string, array{0: string, 1: float, 2: string}>
     */
    public static function invalidTargetProvider(): iterable
    {
        yield 'fromWidth rejects zero target width' => [
            'fromWidth',
            0.0,
            'Placement target width must be greater than zero.',
        ];

        yield 'fromWidth rejects negative target width' => [
            'fromWidth',
            -1.0,
            'Placement target width must be greater than zero.',
        ];

        yield 'fromHeight rejects zero target height' => [
            'fromHeight',
            0.0,
            'Placement target height must be greater than zero.',
        ];

        yield 'fromHeight rejects negative target height' => [
            'fromHeight',
            -1.0,
            'Placement target height must be greater than zero.',
        ];

        yield 'fromScale rejects zero scale' => [
            'fromScale',
            0.0,
            'Placement scale must be greater than zero.',
        ];

        yield 'fromScale rejects negative scale' => [
            'fromScale',
            -1.0,
            'Placement scale must be greater than zero.',
        ];
    }

    /**
     * @return iterable<string, array{0: array{0: float, 1: float, 2: float, 3: float}}>
     */
    public static function invalidBoundingBoxProvider(): iterable
    {
        yield 'zero width bbox is rejected' => [[10.0, 20.0, 10.0, 70.0]];
        yield 'zero height bbox is rejected' => [[10.0, 20.0, 110.0, 20.0]];
        yield 'negative width bbox is rejected' => [[110.0, 20.0, 10.0, 70.0]];
        yield 'negative height bbox is rejected' => [[10.0, 70.0, 110.0, 20.0]];
    }

    #[DataProvider('placementProvider')]
    public function testPlacementStrategiesReturnExpectedGeometry(
        string $strategy,
        array $bbox,
        float $targetValue,
        float $x,
        float $y,
        array $expectedPlacement,
        ?string $pdfAlias = null,
        ?string $expectedPdfCommand = null,
    ): void {
        $calculator = new XObjectPlacementCalculator();
        $result = new CompileResult(contentStream: 'BT ET', resources: [], bbox: $bbox);

        $placement = match ($strategy) {
            'fromWidth' => $calculator->fromWidth($result, $targetValue, $x, $y),
            'fromHeight' => $calculator->fromHeight($result, $targetValue, $x, $y),
            'fromScale' => $calculator->fromScale($result, $targetValue, $x, $y),
            default => throw new InvalidArgumentException('Unknown placement strategy.'),
        };

        self::assertEqualsWithDelta($expectedPlacement['scaleX'], $placement->scaleX, 0.0001);
        self::assertEqualsWithDelta($expectedPlacement['scaleY'], $placement->scaleY, 0.0001);
        self::assertEqualsWithDelta($expectedPlacement['width'], $placement->width, 0.0001);
        self::assertEqualsWithDelta($expectedPlacement['height'], $placement->height, 0.0001);
        self::assertEqualsWithDelta($expectedPlacement['translateX'], $placement->translateX, 0.0001);
        self::assertEqualsWithDelta($expectedPlacement['translateY'], $placement->translateY, 0.0001);

        if ($expectedPdfCommand !== null) {
            self::assertSame($expectedPdfCommand, $placement->toPdfCommand($pdfAlias ?? 'Fm0'));
        }
    }

    #[DataProvider('invalidTargetProvider')]
    public function testPlacementStrategiesRejectNonPositiveTargets(
        string $strategy,
        float $targetValue,
        string $expectedMessage,
    ): void {
        $calculator = new XObjectPlacementCalculator();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        $result = new CompileResult(contentStream: 'BT ET', resources: [], bbox: [0.0, 0.0, 240.0, 84.0]);

        match ($strategy) {
            'fromWidth' => $calculator->fromWidth($result, $targetValue),
            'fromHeight' => $calculator->fromHeight($result, $targetValue),
            'fromScale' => $calculator->fromScale($result, $targetValue),
            default => throw new InvalidArgumentException('Unknown placement strategy.'),
        };
    }

    #[DataProvider('invalidBoundingBoxProvider')]
    public function testPlacementRejectsBoundingBoxesWithoutPositiveArea(array $bbox): void
    {
        $calculator = new XObjectPlacementCalculator();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CompileResult bbox must describe a positive area.');

        $calculator->fromScale(
            new CompileResult(contentStream: 'BT ET', resources: [], bbox: $bbox),
            1.0,
        );
    }
}
