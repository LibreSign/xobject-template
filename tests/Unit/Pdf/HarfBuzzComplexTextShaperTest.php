<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf;

use Closure;
use InvalidArgumentException;
use LibreSign\XObjectTemplate\Pdf\HarfBuzzComplexTextShaper;
use LibreSign\XObjectTemplate\Pdf\TrueTypeFont;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HarfBuzzComplexTextShaperTest extends TestCase
{
    public function testMapsShapedGlyphClusterAndPreservesLogicalUnicode(): void
    {
        $commands = [];
        $runner = static function (array $arguments, ?string $input) use (&$commands): string {
            $commands[] = [$arguments, $input];
            if ($arguments[0] === 'fribidi-test') {
                return "Base direction: L\n0 1\n0 1\n0 0\n";
            }
            self::assertSame('harfbuzz-test', $arguments[0]);
            self::assertContains('--direction=ltr', $arguments);
            self::assertContains('--font-size=1000', $arguments);
            self::assertContains('--text=é', $arguments);
            $fontFile = end($arguments);
            self::assertFileExists($fontFile);
            return '[{"g":4,"cl":0,"ax":530,"dx":12,"dy":-3}]';
        };

        $this->withFont(static function (TrueTypeFont $font) use (&$commands, $runner): void {
            $shaper = new HarfBuzzComplexTextShaper('harfbuzz-test', 'fribidi-test', $runner);
            $result = $shaper->shape("e\u{0301}", $font);
            self::assertSame([[
                'glyph' => 0x65, 'cluster' => 0, 'glyphId' => 4,
                'unicode' => [0x65, 0x301],
                'advance' => 530, 'dx' => 12, 'dy' => -3, 'logical' => 0,
            ]], $result);
            self::assertCount(2, $commands);
            self::assertSame("e\u{0301}\n", $commands[0][1]);
        });
    }

    public function testSegmentsOpposingBidiRunsAndPreservesClusterOrigins(): void
    {
        $runner = static function (array $args): string {
            if ($args[0] === 'fri') {
                return "Base direction: L\n0 1 2 4 3\n0 1 2 4 3\n0 0 0 1 1\n";
            }
            $text = substr($args[5], strlen('--text='));
            if ($text === 'A B') {
                return '[{"g":10,"cl":0,"ax":500,"dx":0,"dy":0},'
                    . '{"g":3,"cl":1,"ax":250,"dx":0,"dy":0},'
                    . '{"g":11,"cl":2,"ax":500,"dx":0,"dy":0}]';
            }
            self::assertSame('של', $text);
            self::assertContains('--direction=rtl', $args);
            return '[{"g":12,"cl":1,"ax":550,"dx":0,"dy":0},'
                . '{"g":13,"cl":0,"ax":650,"dx":0,"dy":0}]';
        };
        $this->withFont(static function (TrueTypeFont $font) use ($runner): void {
            $shaped = (new HarfBuzzComplexTextShaper('hb', 'fri', $runner))->shape('A Bשל', $font);
            self::assertSame([10, 3, 11, 12, 13], array_column($shaped, 'glyphId'));
            self::assertSame([0, 1, 2, 4, 3], array_column($shaped, 'logical'));
            self::assertSame([[65], [32], [66], [0x5DC], [0x5E9]], array_column($shaped, 'unicode'));
        });
    }

    public function testMultipleGlyphsInOneClusterRetainOriginalTextOnce(): void
    {
        $runner = static function (array $args): string {
            if ($args[0] === 'fri') {
                return "Base direction: L\n0 1\n0 1\n0 0\n";
            }
            return '[{"g":12,"cl":0,"ax":400,"dx":0,"dy":0},'
                . '{"g":13,"cl":0,"ax":0,"dx":-50,"dy":200}]';
        };
        $this->withFont(static function (TrueTypeFont $font) use ($runner): void {
            $shaped = (new HarfBuzzComplexTextShaper('hb', 'fri', $runner))->shape("e\u{0301}", $font);
            self::assertSame([0x65, 0x301], $shaped[0]['unicode']);
            self::assertSame([0x200B], $shaped[1]['unicode']);
            self::assertSame([0, 0], array_column($shaped, 'logical'));
            self::assertSame(-50, $shaped[1]['dx']);
            self::assertSame(200, $shaped[1]['dy']);
        });
    }

    public function testEmptyStringDoesNotSpawnProcesses(): void
    {
        $runner = static function (): never {
            throw new \LogicException('No process should be started for empty text.');
        };
        $this->withFont(static function (TrueTypeFont $font) use ($runner): void {
            self::assertSame([], (new HarfBuzzComplexTextShaper('hb', 'fri', $runner))->shape('', $font));
        });
    }

    #[DataProvider('badUnicodeProvider')]
    public function testRejectsUnsafeInput(string $text, string $error): void
    {
        $runner = static function (): never {
            throw new \LogicException('Rejected text must not invoke a process.');
        };
        $this->withFont(function (TrueTypeFont $font) use ($text, $error, $runner): void {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage($error);
            (new HarfBuzzComplexTextShaper('hb', 'fri', $runner))->shape($text, $font);
        });
    }

    /** @return iterable<string, array{text: string, error: string}> */
    public static function badUnicodeProvider(): iterable
    {
        yield 'invalid UTF-8' => ["\xFF", 'UTF-8'];
        yield 'newline' => ["A\nB", 'single line'];
        yield 'nul' => ["A\0B", 'single line'];
        yield 'too many scalars' => [str_repeat('A', 4097), 'at most 4096'];
    }

    #[DataProvider('badBidiProvider')]
    public function testRejectsUntrustedFriBidiOutput(string $output): void
    {
        $runner = static fn (array $args): string => $output;
        $this->withFont(function (TrueTypeFont $font) use ($runner): void {
            $this->expectException(InvalidArgumentException::class);
            (new HarfBuzzComplexTextShaper('hb', 'fri', $runner))->shape('AB', $font);
        });
    }

    /** @return iterable<string, array{output: string}> */
    public static function badBidiProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'not four lines' => ["Base direction: L\n0 1\n0 1"];
        yield 'invalid header' => ["direction: L\n0 1\n0 1\n0 0"];
        yield 'wrong map size' => ["Base direction: L\n0\n0 1\n0 0"];
        yield 'nonnumeric index' => ["Base direction: L\n0 X\n0 1\n0 0"];
        yield 'duplicate index' => ["Base direction: L\n0 0\n0 1\n0 0"];
    }

    #[DataProvider('badGlyphProvider')]
    public function testRejectsUntrustedHarfBuzzOutput(string $output): void
    {
        $runner = static fn (array $args): string => $args[0] === 'fri'
            ? "Base direction: L\n0\n0\n0\n"
            : $output;
        $this->withFont(function (TrueTypeFont $font) use ($runner): void {
            $this->expectException(InvalidArgumentException::class);
            (new HarfBuzzComplexTextShaper('hb', 'fri', $runner))->shape('A', $font);
        });
    }

    /** @return iterable<string, array{output: string}> */
    public static function badGlyphProvider(): iterable
    {
        yield 'malformed JSON' => ['not-json'];
        yield 'empty list' => ['[]'];
        yield 'not a list' => ['{"g":3}'];
        yield 'not a glyph' => ['[5]'];
        yield 'missing position' => ['[{"g":3,"cl":0}]'];
        yield 'string position' => ['[{"g":3,"cl":"0","ax":10,"dx":0,"dy":0}]'];
        yield 'glyph zero' => ['[{"g":0,"cl":0,"ax":10,"dx":0,"dy":0}]'];
        yield 'glyph overflow' => ['[{"g":65536,"cl":0,"ax":10,"dx":0,"dy":0}]'];
        yield 'missing first cluster' => ['[{"g":3,"cl":1,"ax":10,"dx":0,"dy":0}]'];
        yield 'negative cluster' => ['[{"g":3,"cl":-1,"ax":10,"dx":0,"dy":0}]'];
    }

    public function testNativeExecutableFailureIsReported(): void
    {
        $this->withFont(function (TrueTypeFont $font): void {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('working HarfBuzz/FriBidi runtime is required');
            (new HarfBuzzComplexTextShaper('missing-fribidi-binary-for-test'))->shape('A', $font);
        });
    }

    public function testAcceptsMaximumUnicodeScalarLength(): void
    {
        $count = 4096;
        $characters = implode(' ', range(0, $count - 1));
        $levels = str_repeat('0 ', $count);
        $runner = static fn (array $arguments): string => $arguments[0] === 'fri'
            ? "Base direction: L\n" . $characters . "\n" . $characters . "\n" . $levels . "\n"
            : '[{"g":3,"cl":0,"ax":600,"dx":0,"dy":0}]';
        $this->withFont(static function (TrueTypeFont $font) use ($runner, $count): void {
            $result = (new HarfBuzzComplexTextShaper('hb', 'fri', $runner))
                ->shape(str_repeat('A', $count), $font);
            self::assertCount(1, $result);
            self::assertCount($count, $result[0]['unicode']);
        });
    }

    #[DataProvider('shapingRunBoundaries')]
    public function testLimitsNumberOfBidiShapingRuns(int $count, bool $rejected): void
    {
        $indexes = implode(' ', range(0, $count - 1));
        $levels = implode(' ', array_map(static fn (int $index): int => $index % 2, range(0, $count - 1)));
        $runner = static fn (array $arguments): string => $arguments[0] === 'fri'
            ? "Base direction: L\n" . $indexes . "\n" . $indexes . "\n" . $levels . "\n"
            : '[{"g":3,"cl":0,"ax":600,"dx":0,"dy":0}]';
        $this->withFont(function (TrueTypeFont $font) use ($runner, $count, $rejected): void {
            if ($rejected) {
                $this->expectException(InvalidArgumentException::class);
                $this->expectExceptionMessage('safe number of shaping runs');
            }
            $actual = (new HarfBuzzComplexTextShaper('hb', 'fri', $runner))
                ->shape(str_repeat('A', $count), $font);
            self::assertCount($count, $actual);
        });
    }

    /** @return iterable<string, array{count: int, rejected: bool}> */
    public static function shapingRunBoundaries(): iterable
    {
        yield 'maximum accepted' => [32, false];
        yield 'one above maximum' => [33, true];
    }

    /** @param Closure(TrueTypeFont):void $callback */
    private function withFont(Closure $callback): void
    {
        $path = tempnam(sys_get_temp_dir(), 'complex-text-font-');
        self::assertNotFalse($path);
        try {
            file_put_contents($path, SyntheticTrueTypeFontTest::makeFont());
            $callback(new TrueTypeFont($path));
        } finally {
            unlink($path);
        }
    }
}
