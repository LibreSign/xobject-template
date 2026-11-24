<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf;

use InvalidArgumentException;
use LibreSign\XObjectTemplate\Pdf\HarfBuzzRunShaper;
use LibreSign\XObjectTemplate\Pdf\UnicodeScalarCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HarfBuzzRunShaperTest extends TestCase
{
    public function testShapesExactGlyphFieldsAndCommandOptions(): void
    {
        $calls = [];
        $runner = static function (array $command, ?string $stdin) use (&$calls): string {
            $calls[] = [$command, $stdin];
            return '[{"g":65535,"cl":0,"ax":801,"dx":17,"dy":-27},'
                . '{"g":3,"cl":1,"ax":250,"dx":0,"dy":0}]';
        };
        $shaper = new HarfBuzzRunShaper('hb-test', $runner, new UnicodeScalarCodec());
        self::assertSame([
            ['glyph' => 65, 'cluster' => 0, 'glyphId' => 65535,
                'unicode' => [65], 'advance' => 801, 'dx' => 17, 'dy' => -27],
            ['glyph' => 66, 'cluster' => 1, 'glyphId' => 3,
                'unicode' => [66], 'advance' => 250, 'dx' => 0, 'dy' => 0],
        ], $shaper->shape('/tmp/font.ttf', 'AB', true));
        self::assertSame([[
            ['hb-test', '--no-glyph-names', '--output-format=json', '--font-size=1000',
                '--direction=rtl', '--text=AB', '/tmp/font.ttf'],
            null,
        ]], $calls);
    }

    public function testSortsOutOfOrderClustersAndPreservesOriginalUnicode(): void
    {
        $runner = static fn (): string =>
            '[{"g":42,"cl":2,"ax":400,"dx":0,"dy":0},'
            . '{"g":41,"cl":1,"ax":250,"dx":0,"dy":0},'
            . '{"g":40,"cl":0,"ax":350,"dx":0,"dy":0}]';
        $shaped = (new HarfBuzzRunShaper('hb', $runner, new UnicodeScalarCodec()))
            ->shape('/font.ttf', 'AéB', false);
        self::assertSame([2, 1, 0], array_column($shaped, 'cluster'));
        self::assertSame([[66], [0xE9], [65]], array_column($shaped, 'unicode'));
    }

    public function testMultipleGlyphsFromSameClusterPreserveSequenceOnlyOnce(): void
    {
        $runner = static fn (): string =>
            '[{"g":10,"cl":0,"ax":400,"dx":0,"dy":0},'
            . '{"g":11,"cl":0,"ax":0,"dx":-12,"dy":90}]';
        $shaped = (new HarfBuzzRunShaper('hb', $runner, new UnicodeScalarCodec()))
            ->shape('/font.ttf', "e\u{301}", false);
        self::assertSame([[101, 769], [0x200B]], array_column($shaped, 'unicode'));
        self::assertSame([10, 11], array_column($shaped, 'glyphId'));
    }

    public function testReusedClusterAfterAnotherClusterDoesNotDuplicateItsUnicode(): void
    {
        $runner = static fn (): string =>
            '[{"g":10,"cl":0,"ax":400,"dx":0,"dy":0},'
            . '{"g":20,"cl":1,"ax":300,"dx":0,"dy":0},'
            . '{"g":11,"cl":0,"ax":0,"dx":-10,"dy":120}]';
        $shaped = (new HarfBuzzRunShaper('hb', $runner, new UnicodeScalarCodec()))
            ->shape('/font.ttf', 'AB', false);
        self::assertSame([0, 1, 0], array_column($shaped, 'cluster'));
        self::assertSame([[65], [66], [0x200B]], array_column($shaped, 'unicode'));
    }

    #[DataProvider('malformedOutputProvider')]
    public function testRejectsMalformedGlyphOutput(string $output, string $expectedError): void
    {
        $runner = static fn (): string => $output;
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedError);
        (new HarfBuzzRunShaper('hb', $runner, new UnicodeScalarCodec()))
            ->shape('/font.ttf', 'AB', false);
    }

    /** @return iterable<string, array{output: string, expectedError: string}> */
    public static function malformedOutputProvider(): iterable
    {
        yield 'invalid json' => ['not-json', 'invalid JSON'];
        yield 'empty glyph result' => ['[]', 'no glyphs'];
        yield 'json object' => ['{"g":12}', 'no glyphs'];
        yield 'primitive' => ['true', 'no glyphs'];
        yield 'null result' => ['null', 'no glyphs'];
        yield 'non glyph' => ['[5]', 'invalid glyph position'];
        yield 'missing glyph' => ['[{"cl":0,"ax":5,"dx":0,"dy":0}]', 'invalid glyph position'];
        yield 'string g' => ['[{"g":"3","cl":0,"ax":5,"dx":0,"dy":0}]', 'noninteger glyph position'];
        yield 'string cl' => ['[{"g":3,"cl":"0","ax":5,"dx":0,"dy":0}]', 'noninteger glyph position'];
        yield 'string ax' => ['[{"g":3,"cl":0,"ax":"5","dx":0,"dy":0}]', 'noninteger glyph position'];
        yield 'string dx' => ['[{"g":3,"cl":0,"ax":5,"dx":"0","dy":0}]', 'noninteger glyph position'];
        yield 'string dy' => ['[{"g":3,"cl":0,"ax":5,"dx":0,"dy":"0"}]', 'noninteger glyph position'];
        yield 'zero glyph' => ['[{"g":0,"cl":0,"ax":5,"dx":0,"dy":0}]', 'invalid glyph index'];
        yield 'negative glyph' => ['[{"g":-1,"cl":0,"ax":5,"dx":0,"dy":0}]', 'invalid glyph index'];
        yield 'glyph above max' => ['[{"g":65536,"cl":0,"ax":5,"dx":0,"dy":0}]', 'invalid glyph index'];
        yield 'cluster missing start zero' => ['[{"g":3,"cl":1,"ax":5,"dx":0,"dy":0}]', 'incomplete Unicode cluster'];
        yield 'negative cluster' => ['[{"g":3,"cl":-1,"ax":5,"dx":0,"dy":0}]', 'incomplete Unicode cluster'];
        yield 'cluster beyond string length' => [
            '[{"g":3,"cl":0,"ax":5,"dx":0,"dy":0},{"g":4,"cl":3,"ax":4,"dx":0,"dy":0}]',
            'invalid Unicode cluster boundary',
        ];
    }
}
