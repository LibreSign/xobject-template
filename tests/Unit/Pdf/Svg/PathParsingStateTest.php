<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf\Svg;

use LibreSign\XObjectTemplate\Pdf\Svg\PathParsingState;
use PHPUnit\Framework\TestCase;

final class PathParsingStateTest extends TestCase
{
    public function testConstructorDefaultsToOriginAndEmptyCommandList(): void
    {
        $state = new PathParsingState();

        self::assertSame(0.0, $state->currentX);
        self::assertSame(0.0, $state->currentY);
        self::assertNull($state->lastCubicControlX);
        self::assertNull($state->lastCubicControlY);
        self::assertNull($state->prevQuadCpX);
        self::assertNull($state->prevQuadCpY);
        self::assertSame([], $state->commands);
    }

    public function testConstructorAcceptsExplicitStateValues(): void
    {
        $state = new PathParsingState(
            currentX: 10.5,
            currentY: 20.5,
            lastCubicControlX: 30.5,
            lastCubicControlY: 40.5,
            prevQuadCpX: 50.5,
            prevQuadCpY: 60.5,
            commands: ['m', 'l'],
        );

        self::assertSame(10.5, $state->currentX);
        self::assertSame(20.5, $state->currentY);
        self::assertSame(30.5, $state->lastCubicControlX);
        self::assertSame(40.5, $state->lastCubicControlY);
        self::assertSame(50.5, $state->prevQuadCpX);
        self::assertSame(60.5, $state->prevQuadCpY);
        self::assertSame(['m', 'l'], $state->commands);
    }
}
