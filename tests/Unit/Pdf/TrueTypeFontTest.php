<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf;

use InvalidArgumentException;
use LibreSign\XObjectTemplate\Pdf\TrueTypeFont;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TrueTypeFontTest extends TestCase
{
    private const FIXTURE = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';

    public function testReadsActualCmapMetricsAndFontDescriptor(): void
    {
        $font = new TrueTypeFont(self::FIXTURE);
        self::assertGreaterThan(0, $font->glyphFor(ord('A')));
        self::assertGreaterThan(0, $font->glyphFor(0x03A9));
        self::assertGreaterThan(0, $font->widthForGlyph($font->glyphFor(ord('A'))));
        self::assertSame(0, $font->glyphFor(-1));
        self::assertSame(0, $font->glyphFor(0x110000));
        self::assertSame(0, $font->glyphFor(0x10FFFF));
        self::assertSame(file_get_contents(self::FIXTURE), $font->fontData());

        $descriptor = $font->descriptor('FixtureFont', '5 0 R');
        self::assertSame('/FontDescriptor', $descriptor['Type']);
        self::assertSame('/FixtureFont', $descriptor['FontName']);
        self::assertSame('5 0 R', $descriptor['FontFile2']);
        self::assertCount(4, $descriptor['FontBBox']);
        self::assertGreaterThan(0, $descriptor['Ascent']);
        self::assertLessThan(0, $descriptor['Descent']);
    }

    #[DataProvider('utf8Provider')]
    public function testDecodesUnicodeScalars(string $input, array $expected): void
    {
        self::assertSame($expected, TrueTypeFont::codepoints($input));
    }

    /** @return iterable<string, array{input: string, expected: list<int>}> */
    public static function utf8Provider(): iterable
    {
        yield 'ascii' => ['input' => 'A', 'expected' => [65]];
        yield 'two-byte utf8' => ['input' => 'é', 'expected' => [0xE9]];
        yield 'three-byte utf8' => ['input' => '山', 'expected' => [0x5C71]];
        yield 'four-byte utf8' => ['input' => '😀', 'expected' => [0x1F600]];
        yield 'empty' => ['input' => '', 'expected' => []];
    }

    public function testRejectsInvalidUtf8(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TrueTypeFont::codepoints("\xC3\x28");
    }

    public function testSupplementaryUnicodeUsesSurrogatePair(): void
    {
        self::assertSame('0041', TrueTypeFont::utf16Hex(65));
        self::assertSame('D83DDE00', TrueTypeFont::utf16Hex(0x1F600));
    }

    public function testMissingFileIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TrueTypeFont('/nonexistent/libresign-font.ttf');
    }

    #[DataProvider('invalidFontProvider')]
    public function testRejectsInvalidTrueTypeData(string $case): void
    {
        $bytes = file_get_contents(self::FIXTURE);
        self::assertIsString($bytes);
        $data = match ($case) {
            'too short' => 'abc',
            'wrong sfnt signature' => "\x00\x00\x00\x00" . substr($bytes, 4),
            'unreasonable table count' => substr_replace($bytes, pack('n', 65535), 4, 2),
            'table outside file' => substr_replace($bytes, pack('N', 0x7FFFFFFF), 20, 4),
            'missing head' => $this->renameTable($bytes, 'head'),
            'zero units per em' => $this->replaceTableField($bytes, 'head', 18, pack('n', 0)),
            'zero glyph count' => $this->replaceTableField($bytes, 'maxp', 4, pack('n', 0)),
            'missing Unicode cmap' => $this->disableUnicodeCmap($bytes),
            'embedding prohibited' => $this->replaceTableField($bytes, 'OS/2', 8, pack('n', 2)),
            'bitmap-only embedding' => $this->replaceTableField($bytes, 'OS/2', 8, pack('n', 0x200)),
            'too many horizontal metrics' => $this->replaceTableField($bytes, 'hhea', 34, pack('n', 65535)),
            'invalid cmap table size' => $this->shortenCmapTable($bytes),
            'short head table' => $this->shortenTable($bytes, 'head', 16),
            'short hhea table' => $this->shortenTable($bytes, 'hhea', 12),
            'short OS/2 table' => $this->shortenTable($bytes, 'OS/2', 6),
            default => throw new \LogicException('Unknown fixture mutation.'),
        };
        $file = tempnam(sys_get_temp_dir(), 'ttf-fixture-');
        self::assertNotFalse($file);
        try {
            file_put_contents($file, $data);
            $this->expectException(InvalidArgumentException::class);
            new TrueTypeFont($file);
        } finally {
            unlink($file);
        }
    }

    /** @return iterable<string, array{case: string}> */
    public static function invalidFontProvider(): iterable
    {
        foreach (
            [
            'too short',
            'wrong sfnt signature',
            'unreasonable table count',
            'table outside file',
            'missing head',
            'zero units per em',
            'zero glyph count',
            'missing Unicode cmap',
            'embedding prohibited',
            'bitmap-only embedding',
            'too many horizontal metrics',
            'invalid cmap table size',
            'short head table',
            'short hhea table',
            'short OS/2 table',
            ] as $case
        ) {
            yield $case => ['case' => $case];
        }
    }

    public function testRejectsOutOfRangeGlyphMetrics(): void
    {
        $font = new TrueTypeFont(self::FIXTURE);
        $this->expectException(InvalidArgumentException::class);
        $font->widthForGlyph(-1);
    }

    private function shortenTable(string $bytes, string $table, int $length): string
    {
        $entry = $this->tableEntry($bytes, $table);
        return substr_replace($bytes, pack('N', $length), $entry + 12, 4);
    }

    private function shortenCmapTable(string $bytes): string
    {
        $entry = $this->tableEntry($bytes, 'cmap');
        return substr_replace($bytes, pack('N', 4), $entry + 12, 4);
    }

    private function renameTable(string $bytes, string $table): string
    {
        $entry = $this->tableEntry($bytes, $table);
        return substr_replace($bytes, 'XXXX', $entry, 4);
    }

    private function replaceTableField(string $bytes, string $table, int $offset, string $replacement): string
    {
        $entry = $this->tableEntry($bytes, $table);
        $tableOffset = unpack('N', substr($bytes, $entry + 8, 4))[1];
        return substr_replace($bytes, $replacement, $tableOffset + $offset, strlen($replacement));
    }

    private function disableUnicodeCmap(string $bytes): string
    {
        $entry = $this->tableEntry($bytes, 'cmap');
        $offset = unpack('N', substr($bytes, $entry + 8, 4))[1];
        $count = unpack('n', substr($bytes, $offset + 2, 2))[1];
        for ($i = 0; $i < $count; $i++) {
            $bytes = substr_replace($bytes, pack('n', 99), $offset + 4 + $i * 8, 2);
        }
        return $bytes;
    }

    private function tableEntry(string $bytes, string $table): int
    {
        $count = unpack('n', substr($bytes, 4, 2))[1];
        for ($i = 0; $i < $count; $i++) {
            $at = 12 + $i * 16;
            if (substr($bytes, $at, 4) === $table) {
                return $at;
            }
        }
        throw new \LogicException('Unexpected DejaVu fixture directory.');
    }
}
