<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf;

use LibreSign\XObjectTemplate\Pdf\ArabicPresentationShaper;
use LibreSign\XObjectTemplate\Pdf\TrueTypeFont;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArabicPresentationShaperTest extends TestCase
{
    #[DataProvider('shapingProvider')]
    public function testGraphemesUseExpectedVisualOrderAndKeepLogicalUnicode(
        string $text,
        array $expectedLogicalOrder,
    ): void {
        $shaped = (new ArabicPresentationShaper())->shape(TrueTypeFont::codepoints($text));
        $original = array_map(static fn (array $unit): int => $unit['unicode'][0], $shaped);

        self::assertSame($expectedLogicalOrder, $original);
        self::assertCount(count($expectedLogicalOrder), $shaped);
    }

    /** @return iterable<string, array{text: string, expectedLogicalOrder: list<int>}> */
    public static function shapingProvider(): iterable
    {
        yield 'empty' => ['text' => '', 'expectedLogicalOrder' => []];
        yield 'latin unchanged' => ['text' => 'AB', 'expectedLogicalOrder' => [65, 66]];
        yield 'hebrew right-to-left' => [
            'text' => 'שלום',
            'expectedLogicalOrder' => [0x05DD, 0x05D5, 0x05DC, 0x05E9],
        ];
        yield 'arabic right-to-left' => [
            'text' => 'محمد',
            'expectedLogicalOrder' => [0x062F, 0x0645, 0x062D, 0x0645],
        ];
        yield 'mixed Latin and Arabic' => [
            'text' => 'A محمد',
            'expectedLogicalOrder' => [65, 32, 0x062F, 0x0645, 0x062D, 0x0645],
        ];
    }

    public function testArabicGlyphsChooseContextualForms(): void
    {
        $shaped = (new ArabicPresentationShaper())->shape(TrueTypeFont::codepoints('محمد'));
        $glyphs = array_column($shaped, 'glyph');

        self::assertNotSame(0x0645, $glyphs[1]);
        self::assertNotSame(0x062D, $glyphs[2]);
        self::assertNotSame(0x0645, $glyphs[3]);
    }
}
