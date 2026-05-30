<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf\Svg;

use LibreSign\XObjectTemplate\Pdf\Svg\PathCommandContext;
use PHPUnit\Framework\TestCase;

final class PathCommandContextTest extends TestCase
{
    public function testConstructorStoresTransformAndViewportContext(): void
    {
        $matrix = [1.0, 2.0, 3.0, 4.0, 5.0, 6.0];

        $context = new PathCommandContext(
            transformMatrix: $matrix,
            minX: 7.5,
            maxY: 8.5,
            source: '/tmp/example.svg',
        );

        self::assertSame($matrix, $context->transformMatrix);
        self::assertSame(7.5, $context->minX);
        self::assertSame(8.5, $context->maxY);
        self::assertSame('/tmp/example.svg', $context->source);
    }
}
