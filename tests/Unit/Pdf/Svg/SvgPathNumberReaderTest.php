<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf\Svg;

use InvalidArgumentException;
use LibreSign\XObjectTemplate\Pdf\Svg\SvgPathNumberReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SvgPathNumberReaderTest extends TestCase
{
    public function testReadPathNumbersReturnsRequestedNumbersAndAdvancesIndex(): void
    {
        $reader = new SvgPathNumberReader();
        $tokens = ['10', '-2.5', '3e1', 'Z'];
        $index = 0;

        $values = $reader->readPathNumbers($tokens, $index, 3, '/tmp/path.svg');

        self::assertSame([10.0, -2.5, 30.0], $values);
        self::assertSame(3, $index);
    }

    public function testReadPathNumbersAcceptsSingleDigitNumericToken(): void
    {
        $reader = new SvgPathNumberReader();
        $tokens = ['7'];
        $index = 0;

        $values = $reader->readPathNumbers($tokens, $index, 1, '/tmp/path.svg');

        self::assertSame([7.0], $values);
        self::assertSame(1, $index);
    }

    #[DataProvider('provideMalformedTokenScenarios')]
    public function testReadPathNumbersRejectsMalformedSequences(
        array $tokens,
        int $index,
        int $count,
    ): void {
        $reader = new SvgPathNumberReader();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Malformed SVG path data in "/tmp/path.svg".');

        $reader->readPathNumbers($tokens, $index, $count, '/tmp/path.svg');
    }

    /**
     * @return iterable<string, array{tokens: list<string>, index: int, count: int}>
     */
    public static function provideMalformedTokenScenarios(): iterable
    {
        yield 'command token encountered before all numeric values' => [
            'tokens' => ['10', 'M', '20'],
            'index' => 1,
            'count' => 1,
        ];

        yield 'index already beyond available token list' => [
            'tokens' => ['10'],
            'index' => 1,
            'count' => 1,
        ];

        yield 'requested count runs into command token' => [
            'tokens' => ['10', '20', 'L'],
            'index' => 0,
            'count' => 3,
        ];
    }
}
