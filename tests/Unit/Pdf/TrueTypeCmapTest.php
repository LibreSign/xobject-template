<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf;

use InvalidArgumentException;
use LibreSign\XObjectTemplate\Pdf\SfntTableReader;
use LibreSign\XObjectTemplate\Pdf\TrueTypeCmap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TrueTypeCmapTest extends TestCase
{
    #[DataProvider('format4Provider')]
    public function testFormat4MapsSegmentsAndRejectsUnmappedCharacters(
        int $codepoint,
        int $expectedGlyph,
    ): void {
        $cmap = $this->cmap($this->format4(false));
        self::assertSame($expectedGlyph, $cmap->glyphFor($codepoint));
    }

    /** @return iterable<string, array{codepoint: int, expectedGlyph: int}> */
    public static function format4Provider(): iterable
    {
        yield 'mapped A' => [0x41, 3];
        yield 'before mapped segment' => [0x40, 0];
        yield 'after mapped segment' => [0x42, 0];
        yield 'supplementary scalar unsupported' => [0x10000, 0];
        yield 'negative codepoint' => [-1, 0];
        yield 'too high codepoint' => [0x110000, 0];
    }

    public function testFormat4UsesGlyphArrayAndDelta(): void
    {
        $cmap = $this->cmap($this->format4(true));
        self::assertSame(5, $cmap->glyphFor(0x41));
        self::assertSame(0, $cmap->glyphFor(0x40));
    }

    public function testFormat4RejectsOutOfBoundsGlyphArray(): void
    {
        $table = $this->format4(true);
        $table = substr_replace($table, pack('n', 200), 28, 2);
        $cmap = $this->cmap($table);
        $this->expectException(InvalidArgumentException::class);
        $cmap->glyphFor(0x41);
    }

    #[DataProvider('format12Provider')]
    public function testFormat12UsesBinarySearchOverUnicodeGroups(
        int $codepoint,
        int $expectedGlyph,
    ): void {
        $cmap = $this->cmap($this->format12());
        self::assertSame($expectedGlyph, $cmap->glyphFor($codepoint));
    }

    /** @return iterable<string, array{codepoint: int, expectedGlyph: int}> */
    public static function format12Provider(): iterable
    {
        yield 'first group start' => [0x10000, 7];
        yield 'first group middle' => [0x10001, 8];
        yield 'first group end' => [0x10002, 9];
        yield 'gap before second group' => [0x10003, 0];
        yield 'second group start' => [0x1F600, 42];
        yield 'after second group' => [0x1F601, 0];
        yield 'before first group' => [0xFFFF, 0];
        yield 'negative scalar' => [-1, 0];
        yield 'scalar beyond Unicode' => [0x110000, 0];
    }

    public function testPrefersMicrosoftFormat12OverMicrosoftFormat4(): void
    {
        $format4 = $this->format4(false);
        $format12 = $this->format12();
        $body = pack('nn', 0, 2)
            . pack('nnN', 3, 1, 20)
            . pack('nnN', 3, 10, 20 + strlen($format4))
            . $format4 . $format12;
        $cmap = new TrueTypeCmap(new SfntTableReader($body), 0, strlen($body));
        self::assertSame(7, $cmap->glyphFor(0x10000));
        self::assertSame(0, $cmap->glyphFor(0x41));
    }

    public function testRejectsUnsupportedCmapEncoding(): void
    {
        $raw = pack('nnnnN', 0, 1, 3, 0, 12) . $this->format4(false);
        $this->expectException(InvalidArgumentException::class);
        new TrueTypeCmap(new SfntTableReader($raw), 0, strlen($raw));
    }

    #[DataProvider('malformedProvider')]
    public function testRejectsMalformedCmapSubtables(string $bytes): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TrueTypeCmap(new SfntTableReader($bytes), 0, strlen($bytes));
    }

    /** @return iterable<string, array{bytes: string}> */
    public static function malformedProvider(): iterable
    {
        yield 'empty table' => [''];
        yield 'header only' => [pack('nn', 0, 1)];
        yield 'too many records' => [pack('nn', 0, 257)];
        yield 'invalid offset' => [pack('nnnnN', 0, 1, 3, 1, 0xFFFFFFFF)];
        yield 'truncated format 4' => [pack('nnnnN', 0, 1, 3, 1, 12) . pack('nn', 4, 5)];
        yield 'truncated format 12' => [pack('nnnnN', 0, 1, 3, 10, 12)
            . pack('nnNNN', 12, 0, 28, 0, 1)];
    }

    private function cmap(string $subtable): TrueTypeCmap
    {
        $bytes = pack('nnnnN', 0, 1, 3, unpack('n', $subtable)[1] === 12 ? 10 : 1, 12) . $subtable;
        return new TrueTypeCmap(new SfntTableReader($bytes), 0, strlen($bytes));
    }

    private function format4(bool $glyphArray): string
    {
        $length = $glyphArray ? 34 : 32;
        $header = pack('nnnnnnn', 4, $length, 0, 4, 4, 1, 0);
        $segments = pack(
            'nnnnnnnnn',
            0x0041,
            0xFFFF,
            0,
            0x0041,
            0xFFFF,
            $glyphArray ? 1 : (3 - 0x41) & 0xFFFF,
            1,
            $glyphArray ? 4 : 0,
            0,
        );
        return $header . $segments . ($glyphArray ? pack('n', 4) : '');
    }

    private function format12(): string
    {
        return pack('nnNNN', 12, 0, 40, 0, 2)
            . pack('NNN', 0x10000, 0x10002, 7)
            . pack('NNN', 0x1F600, 0x1F600, 42);
    }
}
