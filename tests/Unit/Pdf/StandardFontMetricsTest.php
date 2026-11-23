<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf;

use LibreSign\XObjectTemplate\Pdf\StandardFontMetrics;
use LibreSign\XObjectTemplate\Pdf\TrueTypeFont;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testMeasureStringUsesExpectedBuiltInGlyphWidths(): void
    {
        $metrics = new StandardFontMetrics();

        self::assertEqualsWithDelta(0.3335, $metrics->measureString('F1', 0.5, 'A'), 0.0001);
        self::assertEqualsWithDelta(0.667, $metrics->measureString('F1', 1.0, 'A'), 0.0001);
        self::assertEqualsWithDelta(6.67, $metrics->measureString('F1', 10.0, 'A'), 0.0001);
        self::assertEqualsWithDelta(7.22, $metrics->measureString('F3', 10.0, 'A'), 0.0001);
    }

    public function testMeasureStringFallsBackToReasonableWidthForUnknownGlyphs(): void
    {
        $metrics = new StandardFontMetrics();

        self::assertGreaterThan(0.0, $metrics->measureString('F1', 10.0, "😀"));
    }

    #[DataProvider('unicodeMeasurementProvider')]
    public function testMeasuresEmbeddedUnicodeGlyphWidthsExactly(
        string $text,
        float $size,
        array $scalars,
    ): void {
        $fontPath = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
        self::assertFileExists($fontPath);
        $font = new TrueTypeFont($fontPath);
        $expected = 0.0;
        foreach ($scalars as $scalar) {
            $glyph = $font->glyphFor($scalar);
            self::assertGreaterThan(0, $glyph);
            $expected += $font->widthForGlyph($glyph);
        }
        $metrics = new StandardFontMetrics([$font]);
        self::assertEqualsWithDelta(
            $expected * $size / 1000,
            $metrics->measureString('F1', $size, $text),
            0.0000001,
        );
    }

    /** @return iterable<string, array{text: string, size: float, scalars: list<int>}> */
    public static function unicodeMeasurementProvider(): iterable
    {
        yield 'latin accent small font' => ['é', 1.0, [0xE9]];
        yield 'latin accent large font' => ['é', 24.0, [0xE9]];
        yield 'multiple glyphs' => ['éΩé', 10.0, [0xE9, 0x3A9, 0xE9]];
        yield 'greek uppercase' => ['Ω', 12.0, [0x3A9]];
    }

    public function testSelectsNextUnicodeFontWhenFirstLacksGlyph(): void
    {
        $latin = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
        $cjk = '/usr/share/fonts/truetype/droid/DroidSansFallbackFull.ttf';
        self::assertFileExists($latin);
        self::assertFileExists($cjk);
        $first = new TrueTypeFont($latin);
        $second = new TrueTypeFont($cjk);
        self::assertSame(0, $first->glyphFor(0x5C71));
        $expected = 0;
        foreach ([0x5C71, 0x7530, 0x592A, 0x90CE] as $scalar) {
            $gid = $second->glyphFor($scalar);
            self::assertGreaterThan(0, $gid);
            $expected += $second->widthForGlyph($gid);
        }
        self::assertSame(
            $expected * 12.0 / 1000,
            (new StandardFontMetrics([$first, $second]))->measureString('F1', 12.0, '山田太郎'),
        );
    }

    public function testReturnsZeroForUnicodeWithoutFontCoverage(): void
    {
        $font = new TrueTypeFont('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf');
        self::assertSame(0, $font->glyphFor(0x5C71));
        self::assertSame(0.0, (new StandardFontMetrics([$font]))->measureString('F1', 12.0, '山'));
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
