<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Layout;

use LibreSign\XObjectTemplate\Layout\TextLineBreaker;
use LibreSign\XObjectTemplate\Pdf\StandardFontMetrics;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class TextLineBreakerTest extends TestCase
{
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

    private function invokeBreakerMethod(TextLineBreaker $breaker, string $method, mixed ...$arguments): mixed
    {
        $reflectionMethod = new ReflectionMethod($breaker, $method);

        return $reflectionMethod->invoke($breaker, ...$arguments);
    }
}
