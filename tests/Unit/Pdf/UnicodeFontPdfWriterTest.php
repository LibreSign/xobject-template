<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf;

use InvalidArgumentException;
use LibreSign\XObjectTemplate\Pdf\TrueTypeFont;
use LibreSign\XObjectTemplate\Pdf\UnicodeFontPdfWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UnicodeFontPdfWriterTest extends TestCase
{
    public function testCreatesSixLinkedPdfObjectsWithExactCmapAndFontWidths(): void
    {
        $this->withFont(function (TrueTypeFont $font): void {
            $objects = [];
            $reference = $this->writer()->create($objects, [
                '__TrueTypeFont' => $font,
                '__Mappings' => [
                    ['glyph' => 0x41, 'unicode' => [0x41]],
                    ['glyph' => 0x41, 'unicode' => [0x41, 0x1F600]],
                ],
            ]);
            self::assertSame('6 0 R', $reference);
            self::assertSame([1, 2, 3, 4, 5, 6], array_keys($objects));

            [$fontFileDictionary, $fontData] = unserialize($objects[1], ['allowed_classes' => false]);
            self::assertSame(['Length1' => strlen($font->fontData())], $fontFileDictionary);
            self::assertSame($font->fontData(), $fontData);

            [$cidMapDictionary, $cidMap] = unserialize($objects[2], ['allowed_classes' => false]);
            self::assertSame([], $cidMapDictionary);
            self::assertSame(pack('nnn', 0, 3, 3), $cidMap);

            [$unicodeDictionary, $toUnicode] = unserialize($objects[3], ['allowed_classes' => false]);
            self::assertSame([], $unicodeDictionary);
            self::assertStringContainsString('2 beginbfchar', $toUnicode);
            self::assertStringContainsString('<0001> <0041>', $toUnicode);
            self::assertStringContainsString('<0002> <0041D83DDE00>', $toUnicode);
            self::assertSame(<<<'CMAP'
/CIDInit /ProcSet findresource begin
12 dict begin
begincmap
/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def
/CMapName /LibreSignToUnicode def
/CMapType 2 def
1 begincodespacerange
<0000> <FFFF>
endcodespacerange
2 beginbfchar
<0001> <0041>
<0002> <0041D83DDE00>
endbfchar
endcmap
CMapName currentdict /CMap defineresource pop
end
end

CMAP, $toUnicode);
            self::assertStringContainsString('endcodespacerange', $toUnicode);

            $descriptor = json_decode($objects[4], true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('1 0 R', $descriptor['FontFile2']);
            self::assertSame('/FontDescriptor', $descriptor['Type']);
            $fontName = '/LibreSignUnicode' . substr(hash('sha256', $font->fontData()), 0, 12);
            self::assertSame($fontName, $descriptor['FontName']);
            self::assertSame([-50, -200, 900, 800], $descriptor['FontBBox']);

            $cidFont = json_decode($objects[5], true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('/Font', $cidFont['Type']);
            self::assertSame('/CIDFontType2', $cidFont['Subtype']);
            self::assertSame($fontName, $cidFont['BaseFont']);
            self::assertSame(
                ['Registry' => 'Adobe', 'Ordering' => 'Identity', 'Supplement' => 0],
                $cidFont['CIDSystemInfo'],
            );
            self::assertSame('4 0 R', $cidFont['FontDescriptor']);
            self::assertSame('2 0 R', $cidFont['CIDToGIDMap']);
            self::assertSame(1000, $cidFont['DW']);
            self::assertSame([1, [650, 650]], $cidFont['W']);

            $type0 = json_decode($objects[6], true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('/Font', $type0['Type']);
            self::assertSame('/Type0', $type0['Subtype']);
            self::assertSame($fontName, $type0['BaseFont']);
            self::assertSame('/Identity-H', $type0['Encoding']);
            self::assertSame(['5 0 R'], $type0['DescendantFonts']);
            self::assertSame('3 0 R', $type0['ToUnicode']);
        });
    }

    public function testObjectNumbersFollowExistingPdfObjects(): void
    {
        $this->withFont(function (TrueTypeFont $font): void {
            $objects = [1 => 'catalog', 2 => 'page', 3 => 'contents'];
            $reference = $this->writer()->create($objects, [
                '__TrueTypeFont' => $font,
                '__Mappings' => [['glyph' => 0x41, 'unicode' => [0x41]]],
            ]);
            self::assertSame('9 0 R', $reference);
            self::assertSame('catalog', $objects[1]);
            self::assertSame('page', $objects[2]);
            self::assertSame('contents', $objects[3]);
            self::assertSame(range(1, 9), array_keys($objects));
            $descriptor = json_decode($objects[7], true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('4 0 R', $descriptor['FontFile2']);
        });
    }

    public function testToUnicodeCreatesMultipleMappingBlocksAtHundredEntries(): void
    {
        $this->withFont(function (TrueTypeFont $font): void {
            $mappings = [];
            for ($index = 1; $index <= 101; $index++) {
                $mappings[] = ['glyph' => 0x41, 'unicode' => [0x30 + $index]];
            }
            $objects = [];
            $this->writer()->create($objects, ['__TrueTypeFont' => $font, '__Mappings' => $mappings]);
            [$dictionary, $cmap] = unserialize($objects[3], ['allowed_classes' => false]);
            self::assertSame([], $dictionary);
            self::assertSame(1, substr_count($cmap, '100 beginbfchar'));
            self::assertSame(1, substr_count($cmap, "1 beginbfchar\n"));
            self::assertStringContainsString('<0065> <0095>', $cmap);
            [$cidDictionary, $cidMap] = unserialize($objects[2], ['allowed_classes' => false]);
            self::assertSame([], $cidDictionary);
            self::assertSame(204, strlen($cidMap));
        });
    }

    public function testAcceptsLastValidCidAndRejectsOverflow(): void
    {
        $this->withFont(function (TrueTypeFont $font): void {
            $entry = ['glyph' => 0x41, 'unicode' => [0x41]];
            $mappings = array_fill(0, 65535, $entry);
            $objects = [];
            self::assertSame('6 0 R', $this->writer()->create($objects, [
                '__TrueTypeFont' => $font,
                '__Mappings' => $mappings,
            ]));
            [$dictionary, $cidMap] = unserialize($objects[2], ['allowed_classes' => false]);
            self::assertSame([], $dictionary);
            self::assertSame(131072, strlen($cidMap));
            self::assertSame(pack('n', 3), substr($cidMap, -2));
            $mappings[] = $entry;
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Invalid Unicode CID');
            $overflowObjects = [];
            $this->writer()->create($overflowObjects, [
                '__TrueTypeFont' => $font,
                '__Mappings' => $mappings,
            ]);
        });
    }

    #[DataProvider('invalidResourceProvider')]
    public function testRejectsInvalidFontResource(array $resource): void
    {
        $this->withFont(function (TrueTypeFont $font) use ($resource): void {
            $objects = [];
            if (($resource['__TrueTypeFont'] ?? null) === 'FIXTURE') {
                $resource['__TrueTypeFont'] = $font;
            }
            $this->expectException(InvalidArgumentException::class);
            $this->writer()->create($objects, $resource);
        });
    }

    /** @return iterable<string, array{resource: array<string, mixed>}> */
    public static function invalidResourceProvider(): iterable
    {
        yield 'no font' => [['__Mappings' => [['glyph' => 65, 'unicode' => [65]]]]];
        yield 'incorrect font value' => [['__TrueTypeFont' => new \stdClass()]];
        yield 'no mappings' => [['__TrueTypeFont' => 'FIXTURE']];
        yield 'empty mappings' => [['__TrueTypeFont' => 'FIXTURE', '__Mappings' => []]];
        yield 'scalar mappings' => [['__TrueTypeFont' => 'FIXTURE', '__Mappings' => 123]];
        yield 'associative mappings' => [['__TrueTypeFont' => 'FIXTURE',
            '__Mappings' => ['A' => ['glyph' => 65, 'unicode' => [65]]]]];
        yield 'glyph not integer' => [['__TrueTypeFont' => 'FIXTURE',
            '__Mappings' => [['glyph' => '65', 'unicode' => [65]]]]];
        yield 'unicode not array' => [['__TrueTypeFont' => 'FIXTURE',
            '__Mappings' => [['glyph' => 65, 'unicode' => 'A']]]];
        yield 'empty unicode list' => [['__TrueTypeFont' => 'FIXTURE',
            '__Mappings' => [['glyph' => 65, 'unicode' => []]]]];
        yield 'mixed unicode list' => [['__TrueTypeFont' => 'FIXTURE',
            '__Mappings' => [['glyph' => 65, 'unicode' => [65, 'bad']]]]];
        yield 'missing glyph' => [['__TrueTypeFont' => 'FIXTURE',
            '__Mappings' => [['unicode' => [65]]]]];
        yield 'missing unicode' => [['__TrueTypeFont' => 'FIXTURE',
            '__Mappings' => [['glyph' => 65]]]];
        yield 'no cmap glyph' => [['__TrueTypeFont' => 'FIXTURE',
            '__Mappings' => [['glyph' => 66, 'unicode' => [66]]]]];
        yield 'negative scalar' => [['__TrueTypeFont' => 'FIXTURE',
            '__Mappings' => [['glyph' => 65, 'unicode' => [-1]]]]];
    }

    private function writer(): UnicodeFontPdfWriter
    {
        return new UnicodeFontPdfWriter(
            static fn (array $dictionary, string $data): string => serialize([$dictionary, $data]),
            static fn (array $dictionary): string => json_encode($dictionary, JSON_THROW_ON_ERROR),
        );
    }

    /** @param callable(TrueTypeFont):void $callback */
    private function withFont(callable $callback): void
    {
        $file = tempnam(sys_get_temp_dir(), 'font-writer-fixture-');
        self::assertNotFalse($file);
        try {
            file_put_contents($file, SyntheticTrueTypeFontTest::makeFont());
            $callback(new TrueTypeFont($file));
        } finally {
            unlink($file);
        }
    }
}
