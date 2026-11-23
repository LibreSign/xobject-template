<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf;

use InvalidArgumentException;
use LibreSign\XObjectTemplate\Pdf\TrueTypeFont;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class SyntheticTrueTypeFontTest extends TestCase
{
    #[DataProvider('fontMetricsProvider')]
    public function testReadsAndNormalizesExactFontMetrics(
        int $units,
        int $advance,
        int $ascent,
        int $descent,
        array $bbox,
    ): void {
        $bytes = self::makeFont($units, $advance, $ascent, $descent, $bbox);
        $this->withFont(
            $bytes,
            function (TrueTypeFont $font) use ($units, $advance, $ascent, $descent, $bbox, $bytes): void {
                self::assertSame($bytes, $font->fontData());
                self::assertSame(3, $font->glyphFor(0x41));
                self::assertSame(0, $font->glyphFor(0x42));
                self::assertSame(0, $font->glyphFor(-1));
                self::assertSame(0, $font->glyphFor(0x110000));
                self::assertSame((int) round($advance * 1000 / $units), $font->widthForGlyph(3));
                self::assertSame(0, $font->widthForGlyph(0));
                $descriptor = $font->descriptor('TestFont', '8 0 R');
                self::assertSame('/FontDescriptor', $descriptor['Type']);
                self::assertSame('/TestFont', $descriptor['FontName']);
                self::assertSame(4, $descriptor['Flags']);
                self::assertSame(0, $descriptor['ItalicAngle']);
                self::assertSame(80, $descriptor['StemV']);
                self::assertSame('8 0 R', $descriptor['FontFile2']);
                self::assertSame((int) round($ascent * 1000 / $units), $descriptor['Ascent']);
                self::assertSame((int) round($ascent * 1000 / $units), $descriptor['CapHeight']);
                self::assertSame((int) round($descent * 1000 / $units), $descriptor['Descent']);
                self::assertSame(
                    array_map(fn (int $value): int => (int) round($value * 1000 / $units), $bbox),
                    $descriptor['FontBBox'],
                );
            },
        );
    }

    /** @return iterable<string, array{units: int, advance: int, ascent: int, descent: int, bbox: list<int>}> */
    public static function fontMetricsProvider(): iterable
    {
        yield 'canonical em square' => [1000, 700, 850, -200, [-120, -300, 1000, 900]];
        yield 'power of two em square' => [2048, 1400, 1750, -450, [-50, -450, 2050, 1950]];
        yield 'round down fractional glyph width' => [2048, 650, 450, -40, [-10, -10, 320, 480]];
        yield 'round up fractional glyph width' => [2048, 651, 450, -40, [-10, -10, 320, 480]];
        yield 'tiny glyph width' => [500, 1, 450, -40, [-10, -10, 320, 480]];
        yield 'maximum valid units' => [16384, 12500, 8192, -4096, [-200, -4096, 20000, 18000]];
    }

    #[RunInSeparateProcess]
    #[DataProvider('fontSizeBoundaries')]
    public function testRejectsInvalidFontFileSize(int $size, bool $sizeIsInvalid): void
    {
        $file = tempnam(sys_get_temp_dir(), 'sfnt-size-');
        self::assertNotFalse($file);
        try {
            $handle = fopen($file, 'wb');
            self::assertNotFalse($handle);
            self::assertTrue(ftruncate($handle, $size));
            fclose($handle);
            clearstatcache(true, $file);
            try {
                new TrueTypeFont($file);
                self::fail('A zero-filled font file is invalid.');
            } catch (InvalidArgumentException $exception) {
                if ($sizeIsInvalid) {
                    self::assertStringContainsString('between 64 bytes and 32 MiB', $exception->getMessage());
                } else {
                    self::assertStringNotContainsString('between 64 bytes and 32 MiB', $exception->getMessage());
                }
            }
        } finally {
            unlink($file);
        }
    }

    /** @return iterable<string, array{size: int, sizeIsInvalid: bool}> */
    public static function fontSizeBoundaries(): iterable
    {
        yield 'less than minimum' => [63, true];
        yield 'minimum accepted size' => [64, false];
        yield 'just below maximum' => [33554431, false];
        yield 'maximum accepted size' => [33554432, false];
        yield 'one byte beyond maximum' => [33554433, true];
    }

    public function testUsesLastAdvanceForGlyphsBeyondHorizontalMetricsCount(): void
    {
        $this->withFont(self::makeFont(1000, 650), function (TrueTypeFont $font): void {
            self::assertSame(650, $font->widthForGlyph(1));
            self::assertSame(650, $font->widthForGlyph(3));
            foreach ([-1, 4, 10] as $invalid) {
                try {
                    $font->widthForGlyph($invalid);
                    self::fail('Out-of-range glyphs must be rejected.');
                } catch (InvalidArgumentException $exception) {
                    self::assertStringContainsString('glyph index', $exception->getMessage());
                }
            }
        });
    }

    public function testAcceptsAllDeclaredHorizontalMetrics(): void
    {
        $fontData = self::makeFont();
        $fontData = self::setTableField($fontData, 'hhea', 34, pack('n', 4));
        $fontData = self::setTableLength($fontData, 'hmtx', 16);
        $this->withFont($fontData, static function (TrueTypeFont $font): void {
            self::assertSame(0, $font->widthForGlyph(0));
            self::assertSame(650, $font->widthForGlyph(1));
        });
    }

    #[DataProvider('embeddingFlagsProvider')]
    public function testInterpretsEmbeddingRightsMask(int $flags, bool $restricted): void
    {
        $data = self::setTableField(self::makeFont(), 'OS/2', 8, pack('n', $flags));
        if ($restricted) {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('embedding is restricted');
        }
        $this->withFont($data, static function (TrueTypeFont $font) use ($restricted): void {
            self::assertFalse($restricted);
            self::assertSame(3, $font->glyphFor(65));
        });
    }

    /** @return iterable<string, array{flags: int, restricted: bool}> */
    public static function embeddingFlagsProvider(): iterable
    {
        yield 'installable embedding' => [0, false];
        yield 'other bit zero' => [1, false];
        yield 'preview and print' => [4, false];
        yield 'editable embedding' => [8, false];
        yield 'reserved bit 512 plus 1' => [0x201, true];
        yield 'restricted' => [2, true];
        yield 'bitmap only' => [0x200, true];
    }

    public function testRejectsGlyphMappedBeyondFontGlyphCount(): void
    {
        $fontData = self::makeFont();
        // cmap format 4 delta of U+0041 is at byte 24 in its own table.
        $fontData = self::setTableField($fontData, 'cmap', 12 + 24, pack('n', (4 - 0x41) & 0xFFFF));
        $this->withFont($fontData, static function (TrueTypeFont $font): void {
            self::assertSame(0, $font->glyphFor(0x41));
        });
    }

    public function testRespectsExactHorizontalMetricsTableLength(): void
    {
        $fontData = self::makeFont();
        $fontData = self::setTableLength($fontData, 'hmtx', 8);
        $this->withFont($fontData, static function (TrueTypeFont $font): void {
            self::assertSame(650, $font->widthForGlyph(3));
        });
    }

    public function testRejectsPartiallyTruncatedHorizontalMetrics(): void
    {
        $fontData = self::setTableLength(self::makeFont(), 'hmtx', 6);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Truncated TrueType horizontal metrics table.');
        $this->withFont($fontData, static function (TrueTypeFont $font): void {
        });
    }

    public function testFontWithoutOptionalOs2TableStillEmbeds(): void
    {
        $bytes = self::makeFont();
        $entry = self::directoryPosition($bytes, 'OS/2');
        $bytes = substr_replace($bytes, 'X000', $entry, 4);
        $this->withFont($bytes, static function (TrueTypeFont $font): void {
            self::assertSame(3, $font->glyphFor(0x41));
            self::assertSame(650, $font->widthForGlyph(3));
        });
    }

    public function testRejectsShortReadAfterFileStat(): void
    {
        $scheme = 'syntheticshortfont';
        self::assertTrue(stream_wrapper_register($scheme, TruncatedFontStream::class));
        try {
            clearstatcache();
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('TrueType font file cannot be read.');
            new TrueTypeFont($scheme . '://short-read.ttf');
        } finally {
            stream_wrapper_unregister($scheme);
        }
    }

    public function testAcceptsMaximum128DirectoryTables(): void
    {
        $bytes = self::makeFont();
        $count = 6;
        $extra = 128 - $count;
        $extraRecords = '';
        for ($index = 0; $index < $extra; $index++) {
            $extraRecords .= sprintf('T%03d', $index) . pack('NNN', 0, 0, 0);
        }
        $data = substr($bytes, 12 + 16 * $count);
        $directory = substr($bytes, 0, 12 + 16 * $count);
        $directory = substr_replace($directory, pack('n', 128), 4, 2);
        for ($index = 0; $index < $count; $index++) {
            $position = 12 + 16 * $index + 8;
            $oldOffset = unpack('N', substr($directory, $position, 4))[1];
            $directory = substr_replace($directory, pack('N', $oldOffset + 16 * $extra), $position, 4);
        }
        $this->withFont($directory . $extraRecords . $data, static function (TrueTypeFont $font): void {
            self::assertSame(3, $font->glyphFor(65));
        });
    }

    #[DataProvider('mutatedFontProvider')]
    public function testRejectsMalformedFontTableContracts(string $mutation): void
    {
        $bytes = self::makeFont();
        $bytes = match ($mutation) {
            'wrong header' => substr_replace($bytes, pack('N', 0x4F54544F), 0, 4),
            'no tables' => substr_replace($bytes, pack('n', 0), 4, 2),
            'excessive tables' => substr_replace($bytes, pack('n', 65535), 4, 2),
            'missing cmap' => substr_replace($bytes, 'FAIL', 12 + 4 * 16, 4),
            'negative table offset' => substr_replace($bytes, pack('N', 0xFFFFFFFF), 12 + 8, 4),
            'table exceeds file' => substr_replace($bytes, pack('N', 0xFFFFFFFF), 12 + 12, 4),
            'zero units' => self::setTableField($bytes, 'head', 18, pack('n', 0)),
            'zero glyph count' => self::setTableField($bytes, 'maxp', 4, pack('n', 0)),
            'zero horizontal metrics' => self::setTableField($bytes, 'hhea', 34, pack('n', 0)),
            'too many horizontal metrics' => self::setTableField($bytes, 'hhea', 34, pack('n', 5)),
            'truncated head metrics' => self::setTableLength($bytes, 'head', 20),
            'truncated hhea metrics' => self::setTableLength($bytes, 'hhea', 20),
            'truncated maxp' => self::setTableLength($bytes, 'maxp', 4),
            'truncated horizontal advances' => self::setTableLength($bytes, 'hmtx', 2),
            'restricted embedding' => self::setTableField($bytes, 'OS/2', 8, pack('n', 2)),
            'bitmap only embedding' => self::setTableField($bytes, 'OS/2', 8, pack('n', 0x0200)),
            'truncated embedding rights' => self::setTableLength($bytes, 'OS/2', 8),
            default => throw new \LogicException('Unknown mutation.'),
        };
        $this->expectException(InvalidArgumentException::class);
        if ($mutation === 'zero glyph count') {
            $this->expectExceptionMessage('Invalid TrueType font metrics.');
        } elseif ($mutation === 'too many horizontal metrics') {
            $this->expectExceptionMessage('Invalid TrueType horizontal metrics count.');
        } elseif ($mutation === 'excessive tables') {
            $this->expectExceptionMessage('Invalid TrueType table directory.');
        } elseif ($mutation === 'missing cmap') {
            $this->expectExceptionMessage('Missing required TrueType table.');
        }
        $this->withFont($bytes, static function (TrueTypeFont $font): void {
        });
    }

    /** @return iterable<string, array{mutation: string}> */
    public static function mutatedFontProvider(): iterable
    {
        foreach (
            [
            'wrong header',
            'no tables',
            'excessive tables',
            'missing cmap',
            'negative table offset',
            'table exceeds file',
            'zero units',
            'zero glyph count',
            'zero horizontal metrics',
            'too many horizontal metrics',
            'truncated head metrics',
            'truncated hhea metrics',
            'truncated maxp',
            'truncated horizontal advances',
            'restricted embedding',
            'bitmap only embedding',
            'truncated embedding rights',
            ] as $mutation
        ) {
            yield $mutation => [$mutation];
        }
    }

    public static function makeFont(
        int $units = 1000,
        int $advance = 650,
        int $ascent = 800,
        int $descent = -200,
        array $bbox = [-50, -200, 900, 800],
    ): string {
        $head = str_repeat("\0", 54);
        $head = substr_replace($head, pack('N', 0x5F0F3CF5), 12, 4);
        $head = substr_replace($head, pack('n', $units), 18, 2);
        foreach ($bbox as $index => $value) {
            $head = substr_replace($head, pack('n', $value & 0xFFFF), 36 + 2 * $index, 2);
        }
        $hhea = str_repeat("\0", 36);
        $hhea = substr_replace($hhea, pack('n', $ascent & 0xFFFF), 4, 2);
        $hhea = substr_replace($hhea, pack('n', $descent & 0xFFFF), 6, 2);
        $hhea = substr_replace($hhea, pack('n', 2), 34, 2);
        $hmtx = pack('nnnnnn', 0, 0, $advance, 0, 0, 0);
        $maxp = pack('Nn', 0x00010000, 4) . str_repeat("\0", 26);
        $os2 = str_repeat("\0", 78);

        // Two format 4 segments: U+0041 -> glyph ID 3, terminal U+FFFF.
        $cmap4 = pack('nnnnnnn', 4, 32, 0, 4, 4, 1, 0)
            . pack('nnnnnnnnn', 0x41, 0xFFFF, 0, 0x41, 0xFFFF, (3 - 0x41) & 0xFFFF, 1, 0, 0);
        $cmap = pack('nnnnN', 0, 1, 3, 1, 12) . $cmap4;

        $tables = ['head' => $head, 'hhea' => $hhea, 'hmtx' => $hmtx, 'maxp' => $maxp, 'cmap' => $cmap, 'OS/2' => $os2];
        $directory = pack('Nnnnn', 0x00010000, count($tables), 0, 0, 0);
        $offset = 12 + 16 * count($tables);
        $data = '';
        foreach ($tables as $name => $table) {
            $directory .= $name . pack('NNN', 0, $offset, strlen($table));
            $padding = (4 - strlen($table) % 4) % 4;
            $data .= $table . str_repeat("\0", $padding);
            $offset += strlen($table) + $padding;
        }
        return $directory . $data;
    }

    public static function setTableField(string $bytes, string $name, int $localOffset, string $value): string
    {
        $position = self::directoryPosition($bytes, $name);
        $offset = unpack('N', substr($bytes, $position + 8, 4))[1];
        return substr_replace($bytes, $value, $offset + $localOffset, strlen($value));
    }

    public static function setTableLength(string $bytes, string $name, int $length): string
    {
        return substr_replace($bytes, pack('N', $length), self::directoryPosition($bytes, $name) + 12, 4);
    }

    private static function directoryPosition(string $bytes, string $name): int
    {
        $count = unpack('n', substr($bytes, 4, 2))[1];
        for ($index = 0; $index < $count; $index++) {
            $position = 12 + 16 * $index;
            if (substr($bytes, $position, 4) === $name) {
                return $position;
            }
        }
        throw new \LogicException('Table is absent in the synthetic fixture.');
    }

    /** @param callable(TrueTypeFont):void $callback */
    private function withFont(string $bytes, callable $callback): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sfnt-synthetic-');
        self::assertNotFalse($path);
        try {
            file_put_contents($path, $bytes);
            $callback(new TrueTypeFont($path));
        } finally {
            unlink($path);
        }
    }
}
