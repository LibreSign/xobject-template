<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf;

use LibreSign\XObjectTemplate\Pdf\StandardFontMetrics;
use PHPUnit\Framework\TestCase;

final class StandardFontMetricsTest extends TestCase
{
    public function testMeasureStringReturnsZeroForEmptyTextAndNonPositiveSizes(): void
    {
        $metrics = new StandardFontMetrics();

        self::assertSame(0.0, $metrics->measureString('F1', 10.0, ''));
        self::assertSame(0.0, $metrics->measureString('F1', 0.0, 'abc'));
        self::assertSame(0.0, $metrics->measureString('F1', -5.0, 'abc'));
    }

    public function testMeasureStringDistinguishesNarrowAndWideGlyphsInProportionalFonts(): void
    {
        $metrics = new StandardFontMetrics();

        $narrow = $metrics->measureString('F1', 10.0, 'iiii');
        $wide = $metrics->measureString('F1', 10.0, 'WWWW');

        self::assertGreaterThan($narrow, $wide);
    }

    public function testMeasureStringUsesFixedWidthMetricsForCourierFonts(): void
    {
        $metrics = new StandardFontMetrics();

        $narrow = $metrics->measureString('F5', 10.0, 'iiii');
        $wide = $metrics->measureString('F5', 10.0, 'WWWW');
        $obliqueWide = $metrics->measureString('F6', 10.0, 'WWWW');

        self::assertEqualsWithDelta($narrow, $wide, 0.0001);
        self::assertEqualsWithDelta($wide, $obliqueWide, 0.0001);
    }

    public function testMeasureStringUsesTimesMetricsForTimesAliases(): void
    {
        $metrics = new StandardFontMetrics();

        self::assertSame(
            $metrics->measureString('F3', 10.0, 'A'),
            $metrics->measureString('F4', 10.0, 'A'),
        );
        self::assertNotSame(
            $metrics->measureString('F1', 10.0, 'A'),
            $metrics->measureString('F3', 10.0, 'A'),
        );
    }

    public function testMeasureStringFallsBackToReasonableWidthForUnknownGlyphs(): void
    {
        $metrics = new StandardFontMetrics();

        self::assertGreaterThan(0.0, $metrics->measureString('F1', 10.0, "😀"));
    }

    public function testMeasureStringSplitsUnicodeCharactersAndHandlesInvalidUtf8(): void
    {
        $metrics = new StandardFontMetrics();

        self::assertEqualsWithDelta(
            $metrics->measureString('F1', 10.0, 'A') + $metrics->measureString('F1', 10.0, "😀"),
            $metrics->measureString('F1', 10.0, "A😀"),
            0.0001,
        );
        self::assertSame(0.0, $metrics->measureString('F1', 10.0, "\xc3\x28"));
    }
}
