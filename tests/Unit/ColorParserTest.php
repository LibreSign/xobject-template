<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit;

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

        yield 'invalid color falls back to black' => [
            'input' => 'not-a-color',
            'expected' => '0 0 0 rg',
        ];
    }
}
