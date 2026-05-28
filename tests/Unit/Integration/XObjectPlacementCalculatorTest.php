<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Integration;

use LibreSign\XObjectTemplate\Dto\CompileResult;
use LibreSign\XObjectTemplate\Integration\XObjectPlacementCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class XObjectPlacementCalculatorTest extends TestCase
{
    public function testFromWidthCalculatesUniformPlacementAndPdfCommand(): void
    {
        $calculator = new XObjectPlacementCalculator();
        $placement = $calculator->fromWidth(
            new CompileResult(contentStream: 'BT ET', resources: [], bbox: [12.5, 4.0, 252.5, 88.0]),
            120.0,
            20.0,
            30.0,
        );

        self::assertEqualsWithDelta(0.5, $placement->scaleX, 0.0001);
        self::assertEqualsWithDelta(0.5, $placement->scaleY, 0.0001);
        self::assertEqualsWithDelta(120.0, $placement->width, 0.0001);
        self::assertEqualsWithDelta(42.0, $placement->height, 0.0001);
        self::assertEqualsWithDelta(13.75, $placement->translateX, 0.0001);
        self::assertEqualsWithDelta(28.0, $placement->translateY, 0.0001);
        self::assertSame('q 0.5 0 0 0.5 13.75 28 cm /Fm0 Do Q', $placement->toPdfCommand('Fm0'));
    }

    public function testFromHeightCalculatesUniformPlacementFromBoundingBoxHeight(): void
    {
        $calculator = new XObjectPlacementCalculator();
        $placement = $calculator->fromHeight(
            new CompileResult(contentStream: 'BT ET', resources: [], bbox: [0.0, 0.0, 240.0, 84.0]),
            168.0,
            15.0,
            25.0,
        );

        self::assertEqualsWithDelta(2.0, $placement->scaleX, 0.0001);
        self::assertEqualsWithDelta(2.0, $placement->scaleY, 0.0001);
        self::assertEqualsWithDelta(480.0, $placement->width, 0.0001);
        self::assertEqualsWithDelta(168.0, $placement->height, 0.0001);
        self::assertEqualsWithDelta(15.0, $placement->translateX, 0.0001);
        self::assertEqualsWithDelta(25.0, $placement->translateY, 0.0001);
    }

    public function testFromScaleRejectsNonPositiveScale(): void
    {
        $calculator = new XObjectPlacementCalculator();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Placement scale must be greater than zero.');

        $calculator->fromScale(
            new CompileResult(contentStream: 'BT ET', resources: [], bbox: [0.0, 0.0, 240.0, 84.0]),
            0.0,
        );
    }
}
