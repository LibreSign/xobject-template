<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Layout;

use LibreSign\XObjectTemplate\Layout\LiteralTextLineBreaker;
use LibreSign\XObjectTemplate\Layout\TextLineBreaker;
use LibreSign\XObjectTemplate\Pdf\StandardFontMetrics;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class TextLineBreakerTest extends TestCase
{
    #[DataProvider('literalWrappingProvider')]
    public function testWrapLiteralPreservesGraphemesAndDoesNotInsertHyphens(
        string $input,
        float $width,
        array $expected,
    ): void {
        $breaker = new LiteralTextLineBreaker(new StandardFontMetrics());
        self::assertSame($expected, $breaker->wrap($input, $width, 'F5', 10.0));
    }

    /** @return iterable<string, array{string, float, list<string>}> */
    public static function literalWrappingProvider(): iterable
    {
        yield 'long word' => ['abcdef', 18.0, ['abc', 'def']];
        yield 'word fits on new line' => ['ab cde', 18.0, ['ab', 'cde']];
        yield 'single glyph wider than box' => ['ab', 1.0, ['a', 'b']];
        yield 'canonical accents' => ["e\u{0301}e\u{0301}e\u{0301}", 12.0, ['éé', 'é']];
        yield 'noncomposable combining mark' => ["x\u{0301}y", 6.0, ["x\u{0301}", 'y']];
        yield 'explicit blank lines and all line endings' => ["a\r\n\r\nb\rc", 18.0, ['a', '', 'b', 'c']];
        yield 'whitespace trimmed' => ["  a  ", 60.0, ['a']];
        yield 'exact width preserves internal spaces' => ['a  b', 24.0, ['a  b']];
        yield 'wrap with adjacent spaces' => ['a  b  0', 18.0, ['a b', '0']];
        yield 'spacing retained when it fits' => ["a  b\tc", 60.0, ['a  b c']];
        yield 'nonbreaking space retained when wrapping' => ["a\u{00A0}b c", 18.0, ["a\u{00A0}b", 'c']];
    }

    public function testWrapReturnsOriginalTextForNowrapAndNonPositiveWidths(): void
    {
        $breaker = new TextLineBreaker(new StandardFontMetrics());

        self::assertSame(['alpha beta'], $breaker->wrap('alpha beta', 0.0, 'F1', 10.0, 'auto', 'normal'));
        self::assertSame(['alpha beta'], $breaker->wrap('alpha beta', 100.0, 'F1', 10.0, 'auto', 'nowrap'));
    }

    public function testWrapPreservesWhitespaceOnlyInput(): void
    {
        $breaker = new TextLineBreaker(new StandardFontMetrics());

        self::assertSame([" \t "], $breaker->wrap(" \t ", 20.0, 'F1', 10.0, 'auto', 'normal'));
    }

    public function testWrapKeepsWordsOnTheSameLineWhenTheyStillFit(): void
    {
        $breaker = new TextLineBreaker(new StandardFontMetrics());

        self::assertSame(['a b'], $breaker->wrap('a b', 20.0, 'F5', 10.0, 'none', 'normal'));
    }

    public function testWrapKeepsLongWordsUnbrokenWhenHyphenationIsDisabled(): void
    {
        $breaker = new TextLineBreaker(new StandardFontMetrics());

        self::assertSame(['abcdef'], $breaker->wrap('abcdef', 5.0, 'F1', 10.0, 'none', 'normal'));
    }

    public function testBreakWordKeepsWordUnchangedWhenHyphenationIsDisabled(): void
    {
        $breaker = new TextLineBreaker(new StandardFontMetrics());

        self::assertSame(
            ['abcdef'],
            $this->invokeBreakerMethod($breaker, 'breakWord', 'abcdef', 5.0, 'F1', 10.0, 'none'),
        );
    }

    public function testBreakWordKeepsWordUnchangedForUnsupportedHyphenationMode(): void
    {
        $breaker = new TextLineBreaker(new StandardFontMetrics());

        self::assertSame(
            ['abcdef'],
            $this->invokeBreakerMethod($breaker, 'breakWord', 'abcdef', 5.0, 'F1', 10.0, 'inherit'),
        );
    }

    public function testWrapKeepsAppendingAfterManualHyphenBreaks(): void
    {
        $metrics = new StandardFontMetrics();
        $breaker = new TextLineBreaker($metrics);
        $maxWidth = max(
            $metrics->measureString('F1', 10.0, 'hyphen-'),
            $metrics->measureString('F1', 10.0, 'ation test'),
        );

        self::assertSame(
            ['hyphen-', 'ation test'],
            $breaker->wrap("hyphen\u{00AD}ation test", $maxWidth, 'F1', 10.0, 'manual', 'normal'),
        );
    }

    public function testWrapContinuesUsingTheLastBrokenSegmentAsTheCurrentLine(): void
    {
        $breaker = new TextLineBreaker(new StandardFontMetrics());

        self::assertSame(
            ['abcd-', 'ef gh'],
            $breaker->wrap('abcdef gh', 30.0, 'F5', 10.0, 'auto', 'normal'),
        );
    }

    public function testWrapAutomaticallyHyphenatesFixedWidthWords(): void
    {
        $breaker = new TextLineBreaker(new StandardFontMetrics());

        self::assertSame(
            ['abc-', 'def'],
            $breaker->wrap('abcdef', 24.0, 'F5', 10.0, 'auto', 'normal'),
        );
    }

    public function testWrapAutomaticallyHyphenatesIntoMultipleSegmentsAndLeavesLastSegmentPlain(): void
    {
        $breaker = new TextLineBreaker(new StandardFontMetrics());

        self::assertSame(
            ['ab-', 'cd-', 'efg'],
            $breaker->wrap('abcdefg', 18.0, 'F5', 10.0, 'auto', 'normal'),
        );
    }

    public function testWrapPacksManualSegmentsInOrderAtExactWidthBoundary(): void
    {
        $breaker = new TextLineBreaker(new StandardFontMetrics());

        self::assertSame(
            ['abc-', 'de'],
            $breaker->wrap("ab\u{00AD}c\u{00AD}de", 24.0, 'F5', 10.0, 'manual', 'normal'),
        );
    }

    public function testResolveManualBreaksOnlyRunsForManualSoftHyphenatedWords(): void
    {
        $breaker = new TextLineBreaker(new StandardFontMetrics());

        self::assertNull(
            $this->invokeBreakerMethod($breaker, 'resolveManualBreaks', "ab\u{00AD}cd", 24.0, 'F5', 10.0, 'auto'),
        );
        self::assertNull(
            $this->invokeBreakerMethod($breaker, 'resolveManualBreaks', 'abcd', 24.0, 'F5', 10.0, 'manual'),
        );
        self::assertSame(
            ['ab-', 'cd'],
            $this->invokeBreakerMethod($breaker, 'resolveManualBreaks', "ab\u{00AD}cd", 18.0, 'F5', 10.0, 'manual'),
        );
    }

    public function testPackManualSegmentsContinuesAcrossMultipleOverflowsAndKeepsLastSegmentPlain(): void
    {
        $breaker = new TextLineBreaker(new StandardFontMetrics());

        self::assertSame(
            ['ab-', 'cd-', 'efg'],
            $this->invokeBreakerMethod($breaker, 'packManualSegments', ['ab', 'cd', 'ef', 'g'], 18.0, 'F5', 10.0),
        );
    }

    public function testPackManualSegmentsReturnsFallbackForEmptySegmentList(): void
    {
        $breaker = new TextLineBreaker(new StandardFontMetrics());

        self::assertSame(
            [''],
            $this->invokeBreakerMethod($breaker, 'packManualSegments', [], 18.0, 'F5', 10.0),
        );
    }

    public function testSplitWordsDropsEmptyChunksAndReindexesValues(): void
    {
        $breaker = new TextLineBreaker(new StandardFontMetrics());

        self::assertSame(
            ['alpha', 'beta'],
            $this->invokeBreakerMethod($breaker, 'splitWords', "  alpha\n\tbeta  "),
        );
    }

    public function testWrapFallsBackToSingleCharactersWhenTheFirstAutoSegmentDoesNotFit(): void
    {
        $breaker = new TextLineBreaker(new StandardFontMetrics());

        self::assertSame(
            ['a-', 'b'],
            $breaker->wrap('ab', 5.0, 'F1', 10.0, 'auto', 'normal'),
        );
    }

    public function testWrapReturnsInvalidUtf8WordsUnchangedWhenCharacterSplittingFails(): void
    {
        $breaker = new TextLineBreaker(new StandardFontMetrics());
        $invalidUtf8 = "\xc3\x28";

        self::assertSame([$invalidUtf8], $breaker->wrap($invalidUtf8, 1.0, 'F1', 10.0, 'auto', 'normal'));
    }

    public function testSplitCharactersReturnsEmptyListForInvalidUtf8(): void
    {
        $breaker = new TextLineBreaker(new StandardFontMetrics());

        self::assertSame([], $this->invokeBreakerMethod($breaker, 'splitCharacters', "\xc3\x28"));
    }

    public function testBreakWordAutomaticallyReturnsInvalidUtf8WordWhenCharacterSplittingFails(): void
    {
        $breaker = new TextLineBreaker(new StandardFontMetrics());
        $invalidUtf8 = "\xc3\x28";

        self::assertSame(
            [$invalidUtf8],
            $this->invokeBreakerMethod($breaker, 'breakWordAutomatically', $invalidUtf8, 1.0, 'F1', 10.0, '-'),
        );
    }

    private function invokeBreakerMethod(TextLineBreaker $breaker, string $method, mixed ...$arguments): mixed
    {
        $reflectionMethod = new ReflectionMethod($breaker, $method);

        return $reflectionMethod->invoke($breaker, ...$arguments);
    }
}
