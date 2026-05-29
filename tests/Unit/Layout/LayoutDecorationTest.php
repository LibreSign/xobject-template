<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Layout;

use LibreSign\XObjectTemplate\Layout\LayoutDecoration;
use PHPUnit\Framework\TestCase;

final class LayoutDecorationTest extends TestCase
{
    public function testConstructorDefaultsStrokeWidthAndBorderRadiusToZero(): void
    {
        $decoration = new LayoutDecoration(x: 1.0, y: 2.0, width: 3.0, height: 4.0);

        self::assertSame(1.0, $decoration->x);
        self::assertSame(2.0, $decoration->y);
        self::assertSame(3.0, $decoration->width);
        self::assertSame(4.0, $decoration->height);
        self::assertNull($decoration->fillColor);
        self::assertNull($decoration->strokeColor);
        self::assertSame(0.0, $decoration->strokeWidth);
        self::assertSame(0.0, $decoration->borderRadius);
    }
}
