<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf\Svg;

use LibreSign\XObjectTemplate\Pdf\Svg\ArcParams;
use PHPUnit\Framework\TestCase;

final class ArcParamsTest extends TestCase
{
    public function testWithRadiiReturnsNewInstanceWithUpdatedRadiiOnly(): void
    {
        $params = new ArcParams(
            fromX: 1.0,
            fromY: 2.0,
            toX: 3.0,
            toY: 4.0,
            radiusX: 5.0,
            radiusY: 6.0,
            cosTh: 0.7,
            sinTh: 0.8,
            largeArc: 1,
            sweep: 0,
        );

        $updated = $params->withRadii(9.0, 10.0);

        self::assertNotSame($params, $updated);
        self::assertSame(1.0, $updated->fromX);
        self::assertSame(2.0, $updated->fromY);
        self::assertSame(3.0, $updated->toX);
        self::assertSame(4.0, $updated->toY);
        self::assertSame(9.0, $updated->radiusX);
        self::assertSame(10.0, $updated->radiusY);
        self::assertSame(0.7, $updated->cosTh);
        self::assertSame(0.8, $updated->sinTh);
        self::assertSame(1, $updated->largeArc);
        self::assertSame(0, $updated->sweep);
        self::assertSame(5.0, $params->radiusX);
        self::assertSame(6.0, $params->radiusY);
    }
}
