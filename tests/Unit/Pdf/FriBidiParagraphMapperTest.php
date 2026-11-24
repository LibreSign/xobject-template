<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf;

use InvalidArgumentException;
use LibreSign\XObjectTemplate\Pdf\FriBidiParagraphMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FriBidiParagraphMapperTest extends TestCase
{
    #[DataProvider('runsProvider')]
    public function testSplitsVisualRunsByScriptAndEmbeddingLevel(
        string $input,
        array $visual,
        array $inverse,
        array $levels,
        array $expected,
    ): void {
        $calls = [];
        $runner = static function (
            array $args,
            ?string $stdin
        ) use (
            $input,
            $visual,
            $inverse,
            $levels,
            &$calls,
        ): string {
            $calls[] = [$args, $stdin];
            return "Base direction: L\n"
                . implode(' ', $visual) . "\n"
                . implode(' ', $inverse) . "\n"
                . implode(' ', $levels) . "\n";
        };
        $characters = preg_split('//u', $input, -1, PREG_SPLIT_NO_EMPTY);
        self::assertIsArray($characters);
        $mapper = new FriBidiParagraphMapper('fribidi-test', $runner);
        self::assertSame($expected, $mapper->runs($input, $characters));
        self::assertCount(1, $calls);
        self::assertSame($input . "\n", $calls[0][1]);
        self::assertSame('fribidi-test', $calls[0][0][0]);
        foreach (['--novisual', '--ltov', '--vtol', '--levels', '--charset=UTF-8', '--nopad'] as $option) {
            self::assertContains($option, $calls[0][0]);
        }
    }

    /** @return iterable<string, array{input: string, visual: list<int>, inverse: list<int>, levels: list<int>, expected: list<array{text: string, rtl: bool, visual: int, start: int}>}> */
    public static function runsProvider(): iterable
    {
        yield 'latin and cyrillic split' => [
            'AЖ', [0, 1], [0, 1], [0, 0],
            [
                ['text' => 'A', 'rtl' => false, 'visual' => 0, 'start' => 0],
                ['text' => 'Ж', 'rtl' => false, 'visual' => 1, 'start' => 1],
            ],
        ];
        yield 'Greek and East Asian scripts are itemized separately' => [
            'AΩ山あア한', [0, 1, 2, 3, 4, 5], [0, 1, 2, 3, 4, 5], [0, 0, 0, 0, 0, 0],
            [
                ['text' => 'A', 'rtl' => false, 'visual' => 0, 'start' => 0],
                ['text' => 'Ω', 'rtl' => false, 'visual' => 1, 'start' => 1],
                ['text' => '山', 'rtl' => false, 'visual' => 2, 'start' => 2],
                ['text' => 'あ', 'rtl' => false, 'visual' => 3, 'start' => 3],
                ['text' => 'ア', 'rtl' => false, 'visual' => 4, 'start' => 4],
                ['text' => '한', 'rtl' => false, 'visual' => 5, 'start' => 5],
            ],
        ];
        yield 'hebrew arabic same rtl embedding level' => [
            'שم', [1, 0], [1, 0], [1, 1],
            [
                ['text' => 'م', 'rtl' => true, 'visual' => 0, 'start' => 1],
                ['text' => 'ש', 'rtl' => true, 'visual' => 1, 'start' => 0],
            ],
        ];
        yield 'mixed direction reverse run order' => [
            'ABשم', [0, 1, 3, 2], [0, 1, 3, 2], [0, 0, 1, 1],
            [
                ['text' => 'AB', 'rtl' => false, 'visual' => 0, 'start' => 0],
                ['text' => 'م', 'rtl' => true, 'visual' => 2, 'start' => 3],
                ['text' => 'ש', 'rtl' => true, 'visual' => 3, 'start' => 2],
            ],
        ];
        yield 'punctuation initial merges into following script' => [
            '!A', [0, 1], [0, 1], [0, 0],
            [['text' => '!A', 'rtl' => false, 'visual' => 0, 'start' => 0]],
        ];
        yield 'initial punctuation inherits first script then splits second' => [
            '!AЖ', [0, 1, 2], [0, 1, 2], [0, 0, 0],
            [
                ['text' => '!A', 'rtl' => false, 'visual' => 0, 'start' => 0],
                ['text' => 'Ж', 'rtl' => false, 'visual' => 2, 'start' => 2],
            ],
        ];
        yield 'punctuation after latin' => [
            'A!', [0, 1], [0, 1], [0, 0],
            [['text' => 'A!', 'rtl' => false, 'visual' => 0, 'start' => 0]],
        ];
        yield 'two embedding levels with same Latin script' => [
            'AB', [1, 0], [1, 0], [0, 1],
            [
                ['text' => 'B', 'rtl' => true, 'visual' => 0, 'start' => 1],
                ['text' => 'A', 'rtl' => false, 'visual' => 1, 'start' => 0],
            ],
        ];
        yield 'embedding level three is right to left' => [
            'AB', [1, 0], [1, 0], [3, 3],
            [['text' => 'AB', 'rtl' => true, 'visual' => 0, 'start' => 0]],
        ];
        yield 'inherited Arabic mark does not create its own script run' => [
            "A\u{064E}", [0, 1], [0, 1], [0, 0],
            [['text' => "A\u{064E}", 'rtl' => false, 'visual' => 0, 'start' => 0]],
        ];
        yield 'script-specific Hebrew mark stays attached to a foreign base' => [
            "A\u{05B8}", [0, 1], [0, 1], [0, 0],
            [['text' => "A\u{05B8}", 'rtl' => false, 'visual' => 0, 'start' => 0]],
        ];
        yield 'inherited marks do not split Greek and Cyrillic runs' => [
            "α\u{0301}и\u{0306}", [0, 1, 2, 3], [0, 1, 2, 3], [0, 0, 0, 0],
            [
                ['text' => "α\u{0301}", 'rtl' => false, 'visual' => 0, 'start' => 0],
                ['text' => "и\u{0306}", 'rtl' => false, 'visual' => 2, 'start' => 2],
            ],
        ];
        yield 'combining mark inherits preceding script' => [
            "e\u{0301}", [0, 1], [0, 1], [0, 0],
            [['text' => "e\u{0301}", 'rtl' => false, 'visual' => 0, 'start' => 0]],
        ];
        yield 'even embedding level two is left to right' => [
            'AB', [0, 1], [0, 1], [2, 2],
            [['text' => 'AB', 'rtl' => false, 'visual' => 0, 'start' => 0]],
        ];
        yield 'one neutral scalar' => [
            '!', [0], [0], [0],
            [['text' => '!', 'rtl' => false, 'visual' => 0, 'start' => 0]],
        ];
    }

    #[DataProvider('invalidMappingsProvider')]
    public function testRejectsMalformedBidiMaps(string $output): void
    {
        $runner = static fn (): string => $output;
        $this->expectException(InvalidArgumentException::class);
        (new FriBidiParagraphMapper('fri', $runner))->runs('AB', ['A', 'B']);
    }

    /** @return iterable<string, array{output: string}> */
    public static function invalidMappingsProvider(): iterable
    {
        yield 'missing header' => ["0 1\n0 1\n0 1\n0 0"];
        yield 'extra line' => ["Base direction: L\n0 1\n0 1\n0 0\nEXTRA"];
        yield 'missing line' => ["Base direction: L\n0 1\n0 1"];
        yield 'map too short' => ["Base direction: L\n0\n0 1\n0 0"];
        yield 'negative index' => ["Base direction: L\n0 -1\n0 1\n0 0"];
        yield 'noninteger' => ["Base direction: L\n0 x\n0 1\n0 0"];
        yield 'nonnumeric final level' => ["Base direction: L\n0 1\n0 1\n0 x"];
        yield 'nonnumeric first level' => ["Base direction: L\n0 1\n0 1\nx 0"];
        yield 'nonnumeric inverse' => ["Base direction: L\n0 1\n0 x\n0 0"];
        yield 'wrong inverse' => ["Base direction: L\n1 0\n0 1\n0 0"];
        yield 'duplicate index' => ["Base direction: L\n0 0\n0 1\n0 0"];
        yield 'out of range index' => ["Base direction: L\n0 2\n0 1\n0 0"];
        yield 'surplus level' => ["Base direction: L\n0 1\n0 1\n0 0 0"];
    }
}
