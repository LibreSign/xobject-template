<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf;

use InvalidArgumentException;
use LibreSign\XObjectTemplate\Pdf\DirectGlyphShaper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DirectGlyphShaperTest extends TestCase
{
    #[DataProvider('supportedProvider')]
    public function testMapsIndependentUnicodeScalarsWithoutChangingTheirOrder(string $text): void
    {
        $result = (new DirectGlyphShaper())->shape($text);
        self::assertCount(mb_strlen($text, 'UTF-8'), $result);
        foreach ($result as $mapping) {
            self::assertSame([$mapping['glyph']], $mapping['unicode']);
        }
    }

    /** @return iterable<string, array{text: string}> */
    public static function supportedProvider(): iterable
    {
        yield 'latin' => ['text' => 'Signé par Renée'];
        yield 'greek' => ['text' => 'Αλέξανδρος'];
        yield 'cyrillic' => ['text' => 'Иван Иванов'];
        yield 'CJK' => ['text' => '山田太郎'];
        yield 'ascii' => ['text' => 'ABC'];
    }

    #[DataProvider('requiresShapingProvider')]
    public function testFailsClosedForScriptsRequiringShaping(string $text): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Complex-script text requires');
        (new DirectGlyphShaper())->shape($text);
    }

    /** @return iterable<string, array{text: string}> */
    public static function requiresShapingProvider(): iterable
    {
        yield 'arabic' => ['text' => 'محمد'];
        yield 'hebrew' => ['text' => 'שלום'];
        yield 'decomposed accent' => ['text' => "e\u{0301}"];
    }
}
