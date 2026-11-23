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

    public function testPrioritiesBetweenUnicodeCmapFormatsAndPlatforms(): void
    {
        $format4 = $this->format4(false);
        $format12 = $this->format12();
        foreach (
            [
            'prefer platform 3 format12' => [
                [[0, 4, $format12], [3, 10, $format12], [3, 1, $format4]],
                [0x10000 => 7, 0x41 => 0],
            ],
            'prefer platform 0 format12 over 3 format4' => [
                [[3, 1, $format4], [0, 4, $format12]],
                [0x10000 => 7, 0x41 => 0],
            ],
            'prefer microsoft format4 over platform 0 format4' => [
                [[0, 3, $this->format4WithGlyph(9)], [3, 1, $format4]],
                [0x41 => 3],
            ],
            'ignore unsupported 3/0 format4 if platform 0 exists' => [
                [[3, 0, $this->format4WithGlyph(9)], [0, 3, $format4]],
                [0x41 => 3],
            ],
            'format 12 supported with platform 0 and arbitrary encoding' => [
                [[0, 42, $format12]],
                [0x10000 => 7, 0x1F600 => 42],
            ],
            ] as $case => [$records, $expected]
        ) {
            $bytes = $this->makeCmap($records);
            $cmap = new TrueTypeCmap(new SfntTableReader($bytes), 0, strlen($bytes));
            foreach ($expected as $scalar => $glyph) {
                self::assertSame($glyph, $cmap->glyphFor($scalar), $case);
            }
        }
    }

    public function testFormat4MultipleSegmentsAndGaps(): void
    {
        $subtable = $this->format4WithSegments([
            [0x0041, 0x0042, (10 - 0x0041) & 0xFFFF],
            [0x0061, 0x0063, (20 - 0x0061) & 0xFFFF],
            [0xFFFF, 0xFFFF, 1],
        ]);
        $cmap = $this->cmap($subtable);
        foreach (
            [
            0x40 => 0, 0x41 => 10, 0x42 => 11, 0x43 => 0,
            0x60 => 0, 0x61 => 20, 0x62 => 21, 0x63 => 22, 0x64 => 0,
            0xFFFF => 0, 0x10000 => 0,
            ] as $scalar => $expected
        ) {
            self::assertSame($expected, $cmap->glyphFor($scalar), sprintf('U+%04X', $scalar));
        }
    }

    public function testFormat4GlyphArrayZeroRemainsMissingAfterDelta(): void
    {
        $bytes = $this->format4(true);
        $bytes = substr_replace($bytes, pack('n', 0), strlen($bytes) - 2, 2);
        $cmap = $this->cmap($bytes);
        self::assertSame(0, $cmap->glyphFor(0x41));
    }

    public function testFormat12SearchesMiddleAndLastGroups(): void
    {
        $groups = [
            [0x0041, 0x0042, 3],
            [0x03A9, 0x03A9, 9],
            [0x10000, 0x10003, 11],
            [0x1F600, 0x1F602, 22],
            [0x10FFFD, 0x10FFFF, 50],
        ];
        $cmap = $this->cmap($this->format12WithGroups($groups));
        foreach (
            [
            0x40 => 0, 0x41 => 3, 0x42 => 4, 0x43 => 0,
            0x03A8 => 0, 0x03A9 => 9, 0x03AA => 0,
            0xFFFF => 0, 0x10000 => 11, 0x10002 => 13, 0x10003 => 14, 0x10004 => 0,
            0x1F600 => 22, 0x1F601 => 23, 0x1F602 => 24, 0x1F603 => 0,
            0x10FFFC => 0, 0x10FFFD => 50, 0x10FFFF => 52,
            ] as $scalar => $expected
        ) {
            self::assertSame($expected, $cmap->glyphFor($scalar), sprintf('U+%04X', $scalar));
        }
    }

    public function testFormat12WithNoGroupsHasNoGlyphs(): void
    {
        $cmap = $this->cmap($this->format12WithGroups([]));
        self::assertSame(0, $cmap->glyphFor(65));
        self::assertSame(0, $cmap->glyphFor(0x10000));
    }

    public function testRejectsRecordTableLargerThanDeclaredTable(): void
    {
        $table = $this->makeCmap([[3, 1, $this->format4(false)]]);
        $this->expectException(InvalidArgumentException::class);
        new TrueTypeCmap(new SfntTableReader($table), 0, 11);
    }

    public function testRejectsCmapHeaderShorterThanFourBytes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TrueTypeCmap(new SfntTableReader(str_repeat("\0", 3)), 0, 3);
    }

    public function testRejectsTruncatedFormat12GroupCount(): void
    {
        $subtable = substr_replace($this->format12(), pack('N', 3), 12, 4);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Truncated TrueType format 12 cmap.');
        $this->cmap($subtable);
    }

    public function testRejectsTruncatedFormat4SegmentCount(): void
    {
        $subtable = substr_replace($this->format4(false), pack('n', 10), 6, 2);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Truncated TrueType format 4 cmap.');
        $this->cmap($subtable);
    }

    public function testCmapCanStartAtNonzeroFontOffset(): void
    {
        $prefix = str_repeat("X", 19);
        $table = $this->makeCmap([
            [3, 0, $this->format4WithGlyph(7)],
            [0, 4, $this->format12()],
            [3, 1, $this->format4WithGlyph(11)],
        ]);
        $reader = new SfntTableReader($prefix . $table . 'PADDING');
        $cmap = new TrueTypeCmap($reader, strlen($prefix), strlen($table));
        self::assertSame(7, $cmap->glyphFor(0x10000));
        self::assertSame(0, $cmap->glyphFor(0x41));
    }

    public function testIgnoreBadRecordOffsetWhenValidUnicodeRecordFollows(): void
    {
        $table = $this->makeCmap([
            [0, 4, $this->format4WithGlyph(5)],
            [3, 1, $this->format4WithGlyph(17)],
        ]);
        // First record's subtable address points outside the declared table.
        $table = substr_replace($table, pack('N', 0xFFFFFFFF), 8, 4);
        $cmap = new TrueTypeCmap(new SfntTableReader($table), 0, strlen($table));
        self::assertSame(17, $cmap->glyphFor(0x41));
    }

    public function testAllowsMaximumSupportedRecordCount(): void
    {
        $records = array_fill(0, 255, [3, 0, $this->format4(false)]);
        $records[] = [3, 1, $this->format4WithGlyph(19)];
        $table = $this->makeCmap($records);
        $cmap = new TrueTypeCmap(new SfntTableReader($table), 0, strlen($table));
        self::assertSame(19, $cmap->glyphFor(0x41));
    }

    #[DataProvider('malformedDeclaredCmapProvider')]
    public function testRejectsMalformedDeclaredCmapLength(
        int $declaredLength,
        string $errorPart,
    ): void {
        $table = $this->makeCmap([[3, 1, $this->format4(false)]]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($errorPart);
        new TrueTypeCmap(new SfntTableReader($table), 0, $declaredLength);
    }

    /** @return iterable<string, array{declaredLength: int, errorPart: string}> */
    public static function malformedDeclaredCmapProvider(): iterable
    {
        yield 'less than four' => [3, 'Truncated TrueType cmap table'];
        yield 'record header too short' => [11, 'too many encoding records'];
        yield 'subtable only two bytes' => [14, 'exceeds its table bounds'];
        yield 'subtable one byte short' => [43, 'exceeds its table bounds'];
    }

    public function testRejectsTooManyEncodingRecordsWithExactError(): void
    {
        $table = pack('nn', 0, 257) . str_repeat("\0", 257 * 8);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('too many encoding records');
        new TrueTypeCmap(new SfntTableReader($table), 0, strlen($table));
    }

    public function testRejectsNoEncodingRecordsWithExactError(): void
    {
        $table = pack('nn', 0, 0);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no Unicode cmap');
        new TrueTypeCmap(new SfntTableReader($table), 0, strlen($table));
    }

    #[DataProvider('invalidGroupsProvider')]
    public function testRejectsInvalidFormat12Groups(array $groups): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid TrueType format 12 cmap ranges');
        $this->cmap($this->format12WithGroups($groups));
    }

    /** @return iterable<string, array{groups: list<array{int, int, int}>}> */
    public static function invalidGroupsProvider(): iterable
    {
        yield 'inverted range' => [[[0x41, 0x40, 1]]];
        yield 'unicode beyond maximum' => [[[0x110000, 0x110001, 1]]];
        yield 'overlap' => [[[0x40, 0x42, 1], [0x42, 0x45, 4]]];
        yield 'out-of-order groups' => [[[0x50, 0x52, 3], [0x40, 0x42, 8]]];
    }

    public function testAllowsLastGroupAtMaximumUnicodeScalar(): void
    {
        $cmap = $this->cmap($this->format12WithGroups([
            [0, 0, 3],
            [0x10FFFF, 0x10FFFF, 23],
        ]));
        self::assertSame(3, $cmap->glyphFor(0));
        self::assertSame(23, $cmap->glyphFor(0x10FFFF));
        self::assertSame(0, $cmap->glyphFor(0x110000));
    }

    public function testRejectsExcessiveGroups(): void
    {
        $groups = [];
        for ($i = 0; $i < 16385; $i++) {
            $groups[] = [$i, $i, $i + 1];
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds safe group limit');
        $this->cmap($this->format12WithGroups($groups));
    }

    public function testGlyphArrayUsesSecondSegmentAndCorrectDelta(): void
    {
        $count = 3;
        $body = pack('nnnnnnn', 4, 16 + 8 * $count + 4, 0, 6, 0, 0, 0)
            . pack('nnn', 0x41, 0x43, 0xFFFF)
            . pack('n', 0)
            . pack('nnn', 0x41, 0x42, 0xFFFF)
            . pack('nnn', (3 - 0x41) & 0xFFFF, 2, 1)
            . pack('nnn', 0, 4, 0)
            . pack('nn', 9, 0);
        $cmap = $this->cmap($body);
        self::assertSame(3, $cmap->glyphFor(0x41));
        self::assertSame(11, $cmap->glyphFor(0x42));
        self::assertSame(0, $cmap->glyphFor(0x43));
    }

    public function testFormat4WithoutTerminalSegmentReturnsMissingGlyph(): void
    {
        $cmap = $this->cmap($this->format4WithSegments([[0x41, 0x41, (3 - 0x41) & 0xFFFF]]));
        self::assertSame(3, $cmap->glyphFor(0x41));
        self::assertSame(0, $cmap->glyphFor(0x42));
        self::assertSame(0, $cmap->glyphFor(0xFFFF));
    }

    public function testRejectsFormat4GlyphIndexAtSubtableEnd(): void
    {
        $body = $this->format4(true);
        $body = substr_replace($body, pack('n', 6), 28, 2);
        $cmap = $this->cmap($body);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('glyph index exceeds subtable');
        $cmap->glyphFor(0x41);
    }

    public function testRejectsCmapTableLengthPastEndOfFile(): void
    {
        $bytes = $this->makeCmap([[3, 1, $this->format4(false)]]);
        $this->expectException(InvalidArgumentException::class);
        new TrueTypeCmap(new SfntTableReader($bytes), 0, strlen($bytes) + 1);
    }

    public function testFormat4MaximumScalarMappingIsNotDiscarded(): void
    {
        $cmap = $this->cmap($this->format4WithSegments([[0xFFFF, 0xFFFF, 2]]));
        self::assertSame(1, $cmap->glyphFor(0xFFFF));
        self::assertSame(0, $cmap->glyphFor(0x10000));
    }

    public function testFormat4AcceptsSingleSegmentExactlyAtMinimumLength(): void
    {
        $subtable = $this->format4WithSegments([[0x41, 0x41, (3 - 0x41) & 0xFFFF]]);
        self::assertSame(24, strlen($subtable));
        self::assertSame(3, $this->cmap($subtable)->glyphFor(0x41));
        foreach ([23, 15] as $length) {
            $truncated = substr_replace($subtable, pack('n', $length), 2, 2);
            try {
                $this->cmap($truncated);
                self::fail('Must reject truncated format4 length.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('Truncated TrueType format 4 cmap', $exception->getMessage());
            }
        }
    }

    public function testFormat12RejectsSingleTruncatedGroup(): void
    {
        $subtable = substr_replace($this->format12WithGroups([[0x41, 0x42, 3]]), pack('N', 27), 4, 4);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Truncated TrueType format 12 cmap');
        $this->cmap($subtable);
    }

    public function testFormat12AcceptsMaximumSafeNumberOfGroups(): void
    {
        $groups = [];
        for ($index = 0; $index < 16384; $index++) {
            $groups[] = [$index * 2, $index * 2, $index + 1];
        }
        $cmap = $this->cmap($this->format12WithGroups($groups));
        self::assertSame(1, $cmap->glyphFor(0));
        self::assertSame(16384, $cmap->glyphFor(32766));
    }

    public function testFormat4GlyphArrayHandlesSecondCharacterInRange(): void
    {
        $header = pack('nnnnnnn', 4, 28, 0, 2, 0, 0, 0);
        $body = $header
            . pack('n', 0x42)
            . pack('n', 0)
            . pack('n', 0x41)
            . pack('n', 1)
            . pack('n', 2)
            . pack('nn', 9, 11);
        $cmap = $this->cmap($body);
        self::assertSame(10, $cmap->glyphFor(0x41));
        self::assertSame(12, $cmap->glyphFor(0x42));
    }

    public function testAcceptsMaximumEncodingRecordsInCompactTable(): void
    {
        $subtable = $this->format4WithGlyph(21);
        $count = 256;
        $table = pack('nn', 0, $count)
            . str_repeat(pack('nnN', 3, 1, 4 + $count * 8), $count)
            . $subtable;
        self::assertSame(21, (new TrueTypeCmap(new SfntTableReader($table), 0, strlen($table)))->glyphFor(65));
    }

    public function testRejectsOffTableCmapRecordEvenWhenPhysicalBytesFollow(): void
    {
        $valid = $this->format4WithGlyph(14);
        $firstRecord = pack('nnN', 3, 10, 4 + 16 + strlen($valid) - 1);
        $secondRecord = pack('nnN', 3, 1, 20);
        $table = pack('nn', 0, 2) . $firstRecord . $secondRecord . $valid;
        $physical = $table . pack('nnNNN', 12, 0, 16, 0, 0);
        $cmap = new TrueTypeCmap(new SfntTableReader($physical), 0, strlen($table));
        self::assertSame(14, $cmap->glyphFor(65));
    }

    public function testRejectsGlyphIndexCrossingSubtableBoundaryWithPhysicalPadding(): void
    {
        $subtable = substr_replace($this->format4(true), pack('n', 5), 28, 2);
        $table = $this->makeCmap([[3, 1, $subtable]]) . 'PADDING';
        $cmap = new TrueTypeCmap(new SfntTableReader($table), 0, strlen($table));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('glyph index exceeds subtable');
        $cmap->glyphFor(65);
    }

    public function testRejectsZeroFormat4Segments(): void
    {
        $bytes = pack('nnnnnnn', 4, 16, 0, 0, 0, 0, 0) . pack('n', 0);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Truncated TrueType format 4 cmap');
        $this->cmap($bytes);
    }

    public function testIgnoresEncodedCmapFormatOutsideDeclaredTable(): void
    {
        $good = $this->format4WithGlyph(15);
        $table = pack('nn', 0, 2)
            . pack('nnN', 3, 10, 20 + strlen($good) - 1)
            . pack('nnN', 3, 1, 20)
            . $good;
        $table = substr_replace($table, "\0", -1, 1);
        // Reading the final table byte together with the next physical byte would mimic format 12.
        $physical = $table . "\x0C" . str_repeat("\0", 36);
        $cmap = new TrueTypeCmap(new SfntTableReader($physical), 0, strlen($table));
        self::assertSame(15, $cmap->glyphFor(0x41));
    }

    public function testIgnoresEncodingRecordStartingPastDeclaredCmapEnd(): void
    {
        $good = $this->format4WithGlyph(16);
        $table = pack('nn', 0, 2)
            . pack('nnN', 3, 10, 20 + strlen($good) + 1)
            . pack('nnN', 3, 1, 20)
            . $good;
        $physical = $table . "\0\x0C" . str_repeat("\0", 36);
        $cmap = new TrueTypeCmap(new SfntTableReader($physical), 0, strlen($table));
        self::assertSame(16, $cmap->glyphFor(0x41));
    }

    public function testFormat4FindsTheLastNonTerminalSegment(): void
    {
        $subtable = $this->format4WithSegments([
            [0x41, 0x41, (3 - 0x41) & 0xFFFF],
            [0x61, 0x61, (4 - 0x61) & 0xFFFF],
            [0x7A, 0x7A, (5 - 0x7A) & 0xFFFF],
        ]);
        $cmap = $this->cmap($subtable);
        self::assertSame(3, $cmap->glyphFor(0x41));
        self::assertSame(4, $cmap->glyphFor(0x61));
        self::assertSame(5, $cmap->glyphFor(0x7A));
    }

    public function testFormat4DoesNotReadAnExtraSegmentFromTheReservedPad(): void
    {
        $subtable = $this->format4WithSegments([
            [0x41, 0x41, 0],
        ]);
        // The reservedPad is not another endCode. Force it to FFFF so a
        // malformed extra iteration would decode unrelated table fields.
        $subtable = substr_replace($subtable, pack('n', 0xFFFF), 16, 2);
        $cmap = $this->cmap($subtable);
        self::assertSame(0x41, $cmap->glyphFor(0x41));
        self::assertSame(0, $cmap->glyphFor(0x42));
    }

    /** @param list<array{int, int, string}> $records */
    private function makeCmap(array $records): string
    {
        $bytes = pack('nn', 0, count($records));
        $offset = 4 + 8 * count($records);
        $body = '';
        foreach ($records as [$platform, $encoding, $subtable]) {
            $bytes .= pack('nnN', $platform, $encoding, $offset);
            $body .= $subtable;
            $offset += strlen($subtable);
        }
        return $bytes . $body;
    }

    private function format4WithGlyph(int $glyph): string
    {
        return substr_replace(
            $this->format4(false),
            pack('n', ($glyph - 0x0041) & 0xFFFF),
            24,
            2,
        );
    }

    /** @param list<array{int, int, int}> $segments */
    private function format4WithSegments(array $segments): string
    {
        $count = count($segments);
        $length = 16 + 8 * $count;
        $bytes = pack('nnnnnnn', 4, $length, 0, $count * 2, 0, 0, 0);
        foreach ($segments as $entry) {
            $bytes .= pack('n', $entry[1]);
        }
        $bytes .= pack('n', 0);
        foreach ($segments as $entry) {
            $bytes .= pack('n', $entry[0]);
        }
        foreach ($segments as $entry) {
            $bytes .= pack('n', $entry[2]);
        }
        return $bytes . str_repeat(pack('n', 0), $count);
    }

    /** @param list<array{int, int, int}> $groups */
    private function format12WithGroups(array $groups): string
    {
        $bytes = pack('nnNNN', 12, 0, 16 + count($groups) * 12, 0, count($groups));
        foreach ($groups as [$first, $last, $glyph]) {
            $bytes .= pack('NNN', $first, $last, $glyph);
        }
        return $bytes;
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
