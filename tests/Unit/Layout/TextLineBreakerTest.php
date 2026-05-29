<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Layout;

use LibreSign\XObjectTemplate\Layout\TextLineBreaker;
use LibreSign\XObjectTemplate\Pdf\StandardFontMetrics;
use PHPUnit\Framework\TestCase;

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
}
