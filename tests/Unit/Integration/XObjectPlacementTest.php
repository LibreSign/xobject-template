<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Integration;

use InvalidArgumentException;
use LibreSign\XObjectTemplate\Integration\XObjectPlacement;
use PHPUnit\Framework\TestCase;

final class XObjectPlacementTest extends TestCase
{
    public function testToPdfCommandNormalizesAliasWhitespaceAndLeadingSlash(): void
    {
        $placement = new XObjectPlacement(
            scaleX: 0.5,
            scaleY: 0.75,
            width: 120.0,
            height: 42.0,
            translateX: 13.75,
            translateY: 28.0,
        );

        self::assertSame('q 0.5 0 0 0.75 13.75 28 cm /Fm0 Do Q', $placement->toPdfCommand(' /Fm0 '));
    }

    public function testToPdfCommandRejectsAliasThatBecomesEmptyAfterNormalization(): void
    {
        $placement = new XObjectPlacement(
            scaleX: 1.0,
            scaleY: 1.0,
            width: 10.0,
            height: 10.0,
            translateX: 0.0,
            translateY: 0.0,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Placement alias must not be empty.');

        $placement->toPdfCommand(' / ');
    }
}
