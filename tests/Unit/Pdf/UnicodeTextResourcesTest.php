<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf;

use InvalidArgumentException;
use LibreSign\XObjectTemplate\Layout\LayoutLine;
use LibreSign\XObjectTemplate\Pdf\PdfEscaper;
use LibreSign\XObjectTemplate\Pdf\HarfBuzzComplexTextShaper;
use LibreSign\XObjectTemplate\Pdf\StandardFontMetrics;
use LibreSign\XObjectTemplate\Pdf\UnicodeFontRunSelector;
use LibreSign\XObjectTemplate\Pdf\TrueTypeFont;
use LibreSign\XObjectTemplate\Pdf\UnicodeTextResources;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testDirectFallbackGroupsFontsAndUsesConsistentPositionsAndWidths(): void
    {
        $this->withFonts([0xE9, 0x3A9], function (array $fonts): void {
            $text = 'éêΩé';
            self::assertSame([
                ['fontIndex' => 0, 'text' => 'éê', 'start' => 0],
                ['fontIndex' => 1, 'text' => 'Ω', 'start' => 2],
                ['fontIndex' => 0, 'text' => 'é', 'start' => 3],
            ], (new UnicodeFontRunSelector())->runs($text, $fonts));
            self::assertEqualsWithDelta(
                31.2,
                (new StandardFontMetrics($fonts))->measureString('F1', 12.0, $text),
                0.000001,
            );
            $resources = new UnicodeTextResources([$this->line($text)], $fonts);
            self::assertSame('U1', $resources->aliasFor(0, 'F1'));
            self::assertSame([
                ['glyph' => 0xE9, 'unicode' => [0xE9]],
                ['glyph' => 0xEA, 'unicode' => [0xEA]],
            ], $resources->fonts()['U1']['__Mappings']);
            self::assertSame([['glyph' => 0x3A9, 'unicode' => [0x3A9]]], $resources->fonts()['U2']['__Mappings']);
            self::assertSame(implode("\n", [
                '/Span << /ActualText <FEFF00E900EA03A900E9> >> BDC',
                '1 0 0 1 0.000000 0.000000 Tm <0001> Tj',
                '1 0 0 1 7.800000 0.000000 Tm <0002> Tj',
                '/U2 12.000000 Tf',
                '1 0 0 1 15.600000 0.000000 Tm <0001> Tj',
                '/U1 12.000000 Tf',
                '1 0 0 1 23.400000 0.000000 Tm <0001> Tj',
                'EMC',
            ]), $resources->textOperator(0, $text, new PdfEscaper()));
        });
    }

    public function testFallbackNeverSeparatesBaseAndCombiningMark(): void
    {
        $this->withFonts([0x65, 0x301], static function (array $fonts): void {
            self::assertSame(3, $fonts[0]->glyphFor(0x65));
            self::assertSame(3, $fonts[1]->glyphFor(0x301));
            self::assertNull((new UnicodeFontRunSelector())->runs("e\u{0301}", $fonts));
            $fonts[] = new TrueTypeFont('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf');
            self::assertSame([
                ['fontIndex' => 2, 'text' => "e\u{0301}", 'start' => 0],
            ], (new UnicodeFontRunSelector())->runs("e\u{0301}", $fonts));
        });
    }

    public function testComplexFallbackKeepsJoiningContextAndVisualOrder(): void
    {
        $this->withFonts([0x628, 0x62D], static function (array $fonts): void {
            $text = 'بحب';
            $commands = [];
            $runner = static function (array $args, ?string $input) use (&$commands, $fonts, $text): string {
                if ($args[0] === 'fri') {
                    self::assertSame($text . "\n", $input);
                    return "Base direction: R\n2 1 0\n2 1 0\n1 1 1\n";
                }
                self::assertContains('--direction=rtl', $args);
                $path = $args[array_key_last($args)];
                $fontData = file_get_contents($path);
                $commands[] = [$args, $path];
                $glyph = $args[5] === '--text=ب' ? 3 : 4;
                self::assertSame($fonts[$glyph === 3 ? 0 : 1]->fontData(), $fontData);
                return sprintf('[{"g":%d,"cl":0,"ax":650,"dx":0,"dy":0}]', $glyph);
            };
            $shaper = new HarfBuzzComplexTextShaper('hb', 'fri', $runner);
            $glyphs = $shaper->shapeWithFonts($text, $fonts);
            self::assertSame([2, 1, 0], array_column($glyphs, 'logical'));
            self::assertSame([0, 1, 0], array_column($glyphs, 'fontIndex'));
            self::assertSame([[0x628], [0x62D], [0x628]], array_column($glyphs, 'unicode'));
            self::assertCount(3, $commands);
            self::assertContains('--text-before=بح', $commands[0][0]);
            self::assertNotContains('--text-after=', $commands[0][0]);
            self::assertContains('--text-before=ب', $commands[1][0]);
            self::assertContains('--text-after=ب', $commands[1][0]);
            self::assertContains('--text-after=حب', $commands[2][0]);
            self::assertNotContains('--text-before=', $commands[2][0]);
            foreach ($commands as [, $path]) {
                self::assertFileDoesNotExist($path);
            }
        });
    }

    public function testComplexFallbackRejectsUncoveredTextBeforeInvokingHarfBuzz(): void
    {
        $this->withFonts([0x628], function (array $fonts): void {
            $runner = static function (array $args): string {
                self::assertSame('fri', $args[0]);
                return "Base direction: R\n0\n0\n1\n";
            };
            $shaper = new HarfBuzzComplexTextShaper('hb', 'fri', $runner);
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Unicode text requires an embedded TrueType font');
            $shaper->shapeWithFonts('ح', $fonts);
        });
    }

    public function testComplexFallbackTranslatesRunRelativeClustersToParagraphIndices(): void
    {
        $this->withFonts([0x41, 0x628], static function (array $fonts): void {
            $runner = static function (array $args): string {
                if ($args[0] === 'fri') {
                    return "Base direction: L\n0 2 1\n0 2 1\n0 1 1\n";
                }
                if ($args[5] === '--text=A') {
                    return '[{"g":3,"cl":0,"ax":600,"dx":0,"dy":0}]';
                }
                self::assertSame('--text=بة', $args[5]);
                return '[{"g":4,"cl":1,"ax":600,"dx":0,"dy":0},{"g":3,"cl":0,"ax":600,"dx":0,"dy":0}]';
            };
            $glyphs = (new HarfBuzzComplexTextShaper('hb', 'fri', $runner))->shapeWithFonts('Aبة', $fonts);
            self::assertSame([0, 2, 1], array_column($glyphs, 'logical'));
            self::assertSame([0, 1, 1], array_column($glyphs, 'fontIndex'));
        });
    }

    #[DataProvider('fontRunLimitProvider')]
    public function testComplexFallbackLimitsTotalFontRunsNotJustBidiRuns(int $count, bool $reject): void
    {
        $this->withFonts([0x628, 0x62D], function (array $fonts) use ($count, $reject): void {
            $indexes = implode(' ', range($count - 1, 0));
            $runner = static function (array $args) use ($indexes, $count): string {
                if ($args[0] === 'fri') {
                    return "Base direction: R\n" . $indexes . "\n" . $indexes . "\n" . str_repeat('1 ', $count) . "\n";
                }
                return '[{"g":3,"cl":0,"ax":650,"dx":0,"dy":0}]';
            };
            $shaper = new HarfBuzzComplexTextShaper('hb', 'fri', $runner);
            $text = str_repeat('بح', intdiv($count, 2)) . ($count % 2 === 1 ? 'ب' : '');
            if ($reject) {
                $this->expectException(InvalidArgumentException::class);
                $this->expectExceptionMessage('safe number of shaping runs');
            }
            $glyphs = $shaper->shapeWithFonts($text, $fonts);
            self::assertCount($count, $glyphs);
        });
    }

    /** @return iterable<string, array{int, bool}> */
    public static function fontRunLimitProvider(): iterable
    {
        yield 'maximum accepted' => [32, false];
        yield 'one above maximum' => [33, true];
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
