<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf;

use LibreSign\XObjectTemplate\Pdf\StandardFontMetrics;
use PHPUnit\Framework\TestCase;

final class StandardFontMetricsTest extends TestCase
{
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

        self::assertEqualsWithDelta($narrow, $wide, 0.0001);
    }

    public function testMeasureStringFallsBackToReasonableWidthForUnknownGlyphs(): void
    {
        $metrics = new StandardFontMetrics();

        self::assertGreaterThan(0.0, $metrics->measureString('F1', 10.0, "😀"));
    }
}
