<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf;

use InvalidArgumentException;
use LibreSign\XObjectTemplate\Layout\LayoutLine;
use LibreSign\XObjectTemplate\Pdf\PdfEscaper;
use LibreSign\XObjectTemplate\Pdf\TrueTypeFont;
use LibreSign\XObjectTemplate\Pdf\UnicodeTextResources;
use PHPUnit\Framework\TestCase;

final class UnicodeTextResourcesTest extends TestCase
{
    public function testKeepsAsciiLinesAndMapsLaterUnicodeLines(): void
    {
        $this->withFonts([0xE9], function (array $fonts): void {
            $resources = new UnicodeTextResources([
                $this->line('Before (ascii)'),
                $this->line('é'),
                $this->line('After ascii'),
                $this->line('é'),
            ], $fonts);
            self::assertSame('F1', $resources->aliasFor(0, 'F1'));
            self::assertSame('U1', $resources->aliasFor(1, 'F1'));
            self::assertSame('F4', $resources->aliasFor(2, 'F4'));
            self::assertSame('U1', $resources->aliasFor(3, 'F1'));
            self::assertSame(
                '(Before \\(ascii\\)) Tj',
                $resources->textOperator(0, 'Before (ascii)', new PdfEscaper()),
            );
            self::assertSame('<0001> Tj', $resources->textOperator(1, 'é', new PdfEscaper()));
            self::assertSame('(After ascii) Tj', $resources->textOperator(2, 'After ascii', new PdfEscaper()));
            self::assertSame('<0001> Tj', $resources->textOperator(3, 'é', new PdfEscaper()));
            $selected = $resources->fonts();
            self::assertSame(['U1'], array_keys($selected));
            self::assertSame([['glyph' => 0xE9, 'unicode' => [0xE9]]], $selected['U1']['__Mappings']);
            self::assertSame($fonts[0], $selected['U1']['__TrueTypeFont']);
        });
    }

    public function testPreservesMultipleFontAliasesAndMappings(): void
    {
        $this->withFonts([0xE9, 0x3A9], function (array $fonts): void {
            $resources = new UnicodeTextResources([
                $this->line('é'),
                $this->line('Ω'),
                $this->line('é'),
            ], $fonts);
            self::assertSame('U1', $resources->aliasFor(0, 'F1'));
            self::assertSame('U2', $resources->aliasFor(1, 'F1'));
            self::assertSame('U1', $resources->aliasFor(2, 'F1'));
            self::assertSame(['U1', 'U2'], array_keys($resources->fonts()));
            self::assertSame('<0001> Tj', $resources->textOperator(0, 'é', new PdfEscaper()));
            self::assertSame('<0001> Tj', $resources->textOperator(1, 'Ω', new PdfEscaper()));
            self::assertSame([['glyph' => 0x3A9, 'unicode' => [0x3A9]]], $resources->fonts()['U2']['__Mappings']);
        });
    }

    public function testRetainsDistinctMappingsAndReusesRepeatedCid(): void
    {
        $this->withFonts([0xE9], function (array $fonts): void {
            $resources = new UnicodeTextResources([
                $this->line('éêé'),
                $this->line('ê'),
            ], $fonts);
            self::assertSame('<000100020001> Tj', $resources->textOperator(0, 'éêé', new PdfEscaper()));
            self::assertSame('<0002> Tj', $resources->textOperator(1, 'ê', new PdfEscaper()));
            self::assertSame([
                ['glyph' => 0xE9, 'unicode' => [0xE9]],
                ['glyph' => 0xEA, 'unicode' => [0xEA]],
            ], $resources->fonts()['U1']['__Mappings']);
        });
    }

    public function testFailsWhenNoFontCanCoverRequiredUnicodeScalars(): void
    {
        $this->withFonts([0xE9], function (array $fonts): void {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Unicode text requires an embedded TrueType font');
            new UnicodeTextResources([$this->line('Ω')], $fonts);
        });
    }

    private function line(string $text): LayoutLine
    {
        return new LayoutLine($text, 0.0, 0.0, 12.0, 'F1', '#000000');
    }

    /** @param list<int> $starts */
    private function withFonts(array $starts, callable $callback): void
    {
        $paths = [];
        $fonts = [];
        try {
            foreach ($starts as $scalar) {
                $bytes = SyntheticTrueTypeFontTest::makeFont();
                $bytes = SyntheticTrueTypeFontTest::setTableField($bytes, 'maxp', 4, pack('n', 5));
                $bytes = SyntheticTrueTypeFontTest::setTableField($bytes, 'cmap', 12 + 14, pack('n', $scalar + 1));
                $bytes = SyntheticTrueTypeFontTest::setTableField($bytes, 'cmap', 12 + 20, pack('n', $scalar));
                $bytes = SyntheticTrueTypeFontTest::setTableField(
                    $bytes,
                    'cmap',
                    12 + 24,
                    pack('n', (3 - $scalar) & 0xFFFF),
                );
                $path = tempnam(sys_get_temp_dir(), 'font-resources-');
                self::assertNotFalse($path);
                $paths[] = $path;
                file_put_contents($path, $bytes);
                $fonts[] = new TrueTypeFont($path);
            }
            $callback($fonts);
        } finally {
            foreach ($paths as $path) {
                unlink($path);
            }
        }
    }
}
