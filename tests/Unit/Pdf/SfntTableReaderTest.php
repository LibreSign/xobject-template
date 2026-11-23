<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf;

use InvalidArgumentException;
use LibreSign\XObjectTemplate\Pdf\SfntTableReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SfntTableReaderTest extends TestCase
{
    #[DataProvider('wordProvider')]
    public function testBigEndianWordsAndSignedValues(string $bytes, int $u16, int $s16, int $u32): void
    {
        $reader = new SfntTableReader($bytes);
        self::assertSame(strlen($bytes), $reader->length());
        self::assertSame($u16, $reader->u16(0));
        self::assertSame($s16, $reader->s16(0));
        self::assertSame($u32, $reader->u32(0));
        $reader->requireRange(0, strlen($bytes));
        $reader->requireRange(strlen($bytes), 0);
    }

    /** @return iterable<string, array{bytes: string, u16: int, s16: int, u32: int}> */
    public static function wordProvider(): iterable
    {
        yield 'all zeros' => ["\x00\x00\x00\x00", 0, 0, 0];
        yield 'positive boundary' => ["\x7F\xFF\xFF\xFF", 32767, 32767, 0x7FFFFFFF];
        yield 'negative boundary' => ["\x80\x00\x00\x01", 32768, -32768, 0x80000001];
        yield 'negative one' => ["\xFF\xFF\xFF\xFF", 65535, -1, 0xFFFFFFFF];
        yield 'arbitrary' => ["\x12\x34\x56\x78", 0x1234, 0x1234, 0x12345678];
    }

    #[DataProvider('badRangesProvider')]
    public function testRejectsInvalidReadRange(int $offset, int $length): void
    {
        $reader = new SfntTableReader('ABCDE');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('outside font bounds');
        $reader->requireRange($offset, $length);
    }

    /** @return iterable<string, array{offset: int, length: int}> */
    public static function badRangesProvider(): iterable
    {
        yield 'negative offset' => [-1, 1];
        yield 'negative size' => [0, -1];
        yield 'offset one past end' => [6, 0];
        yield 'range past end' => [4, 2];
        yield 'size exceeds entire buffer' => [0, 6];
        yield 'overflow sized offset' => [PHP_INT_MAX, 1];
        yield 'overflow sized length' => [0, PHP_INT_MAX];
    }

    public function testReadsAtUnalignedOffsets(): void
    {
        $reader = new SfntTableReader("X\xAA\xBB\xCC\xDDY");
        self::assertSame(0xAABB, $reader->u16(1));
        self::assertSame(-21829, $reader->s16(1));
        self::assertSame(0xAABBCCDD, $reader->u32(1));
    }

    public function testReadsMustRejectThreeByteWordBeforeUnpack(): void
    {
        $reader = new SfntTableReader("\x12\x34\x56");
        self::assertSame(0x1234, $reader->u16(0));
        $this->expectException(InvalidArgumentException::class);
        $reader->u32(0);
    }

    public function testReadMethodsRejectTruncatedData(): void
    {
        $reader = new SfntTableReader("A");
        foreach (['u16', 's16', 'u32'] as $method) {
            try {
                $reader->{$method}(0);
                self::fail($method . ' must reject an incomplete integer.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('outside font bounds', $exception->getMessage());
            }
        }
    }
}
