<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf;

use LibreSign\XObjectTemplate\Pdf\ColorParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ColorParserTest extends TestCase
{
    #[DataProvider('colorProvider')]
    public function testToPdfRgbConvertsSupportedFormats(string $input, string $expected): void
    {
        $parser = new ColorParser();

        self::assertSame($expected, $parser->toPdfRgb($input));
    }

    #[DataProvider('strokeColorProvider')]
    public function testToPdfStrokeRgbConvertsSupportedFormats(string $input, string $expected): void
    {
        $parser = new ColorParser();

        self::assertSame($expected, $parser->toPdfStrokeRgb($input));
    }

    /**
     * @return iterable<string, array{input: string, expected: string}>
     */
    public static function colorProvider(): iterable
    {
        yield 'six-digit hex' => [
            'input' => '#123456',
            'expected' => '0.0706 0.2039 0.3373 rg',
        ];

        yield 'three-digit hex' => [
            'input' => '#abc',
            'expected' => '0.6667 0.7333 0.8 rg',
        ];

        yield 'trim and uppercase are supported' => [
            'input' => '  #AaBbCc  ',
            'expected' => '0.6667 0.7333 0.8 rg',
        ];

        yield 'invalid color falls back to black' => [
            'input' => 'not-a-color',
            'expected' => '0 0 0 rg',
        ];

        yield 'rejects extra trailing digits' => [
            'input' => '#1234567',
            'expected' => '0 0 0 rg',
        ];

        yield 'rejects prefixed six-digit tail' => [
            'input' => 'x123456',
            'expected' => '0 0 0 rg',
        ];
    }

    /**
     * @return iterable<string, array{input: string, expected: string}>
     */
    public static function strokeColorProvider(): iterable
    {
        yield 'six-digit hex stroke' => [
            'input' => '#123456',
            'expected' => '0.0706 0.2039 0.3373 RG',
        ];

        yield 'three-digit hex stroke' => [
            'input' => '#abc',
            'expected' => '0.6667 0.7333 0.8 RG',
        ];

        yield 'invalid stroke color falls back to black' => [
            'input' => 'not-a-color',
            'expected' => '0 0 0 RG',
        ];
    }
}
