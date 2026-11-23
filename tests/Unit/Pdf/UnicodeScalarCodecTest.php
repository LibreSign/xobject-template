<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf;

use InvalidArgumentException;
use LibreSign\XObjectTemplate\Pdf\UnicodeScalarCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UnicodeScalarCodecTest extends TestCase
{
    #[DataProvider('scalarsProvider')]
    public function testUtf8AndUtf16PreserveUnicodeScalarValues(
        string $utf8,
        int $scalar,
        string $utf16Hex,
    ): void {
        $codec = new UnicodeScalarCodec();
        self::assertSame([$scalar], $codec->decode($utf8));
        self::assertSame($utf16Hex, $codec->encodeUtf16Hex($scalar));
    }

    /** @return iterable<string, array{utf8: string, scalar: int, utf16Hex: string}> */
    public static function scalarsProvider(): iterable
    {
        yield 'nul' => ["\0", 0, '0000'];
        yield 'ascii' => ['A', 0x41, '0041'];
        yield 'utf8 two-byte lower edge' => ["\xC2\x80", 0x80, '0080'];
        yield 'utf8 two-byte upper edge' => ["\xDF\xBF", 0x7FF, '07FF'];
        yield 'utf8 three-byte lower edge' => ["\xE0\xA0\x80", 0x800, '0800'];
        yield 'utf8 three-byte upper edge' => ["\xEF\xBF\xBF", 0xFFFF, 'FFFF'];
        yield 'utf8 four-byte lower edge' => ["\xF0\x90\x80\x80", 0x10000, 'D800DC00'];
        yield 'emoji' => ['😀', 0x1F600, 'D83DDE00'];
        yield 'utf8 four-byte upper edge' => ["\xF4\x8F\xBF\xBF", 0x10FFFF, 'DBFFDFFF'];
    }

    #[DataProvider('invalidScalarsProvider')]
    public function testRejectsInvalidUtf16Scalar(int $scalar): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new UnicodeScalarCodec())->encodeUtf16Hex($scalar);
    }

    /** @return iterable<string, array{scalar: int}> */
    public static function invalidScalarsProvider(): iterable
    {
        yield 'negative' => [-1];
        yield 'just above Unicode maximum' => [0x110000];
        yield 'high surrogate start' => [0xD800];
        yield 'high surrogate end' => [0xDBFF];
        yield 'low surrogate start' => [0xDC00];
        yield 'low surrogate end' => [0xDFFF];
    }

    #[DataProvider('invalidUtf8Provider')]
    public function testRejectsMalformedUtf8(string $invalid): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new UnicodeScalarCodec())->decode($invalid);
    }

    /** @return iterable<string, array{invalid: string}> */
    public static function invalidUtf8Provider(): iterable
    {
        yield 'overlong' => ["\xC0\xAF"];
        yield 'stray continuation' => ["\x80"];
        yield 'truncated two-byte' => ["\xC3"];
        yield 'truncated three-byte' => ["\xE2\x82"];
        yield 'surrogate UTF8' => ["\xED\xA0\x80"];
        yield 'out of Unicode range' => ["\xF4\x90\x80\x80"];
    }

    public function testMultiScalarStreamPreservesEveryPosition(): void
    {
        $codec = new UnicodeScalarCodec();
        self::assertSame(
            [0, 65, 0x80, 0x7FF, 0x800, 0xFFFF, 0x10000, 0x1F600, 0x10FFFF],
            $codec->decode("\0A\xC2\x80\xDF\xBF\xE0\xA0\x80\xEF\xBF\xBF\xF0\x90\x80\x80😀\xF4\x8F\xBF\xBF"),
        );
        self::assertSame([], $codec->decode(''));
    }
}
