<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Layout;

use LibreSign\XObjectTemplate\Layout\TextOverflowTruncator;
use LibreSign\XObjectTemplate\Pdf\StandardFontMetrics;
use PHPUnit\Framework\TestCase;

final class TextOverflowTruncatorTest extends TestCase
{
    public function testTruncateWithEllipsisLeavesExactFitStringsUntouched(): void
    {
        $metrics = new StandardFontMetrics();
        $truncator = new TextOverflowTruncator($metrics);
        $text = 'exact fit';

        self::assertSame(
            $text,
            $truncator->truncateWithEllipsis($text, $metrics->measureString('F1', 10.0, $text), 'F1', 10.0),
        );
    }

    public function testTruncateWithEllipsisShortensUntilTheCandidateFits(): void
    {
        $metrics = new StandardFontMetrics();
        $truncator = new TextOverflowTruncator($metrics);

        self::assertSame(
            'ab...',
            $truncator->truncateWithEllipsis('abcdef', $metrics->measureString('F5', 10.0, 'ab...'), 'F5', 10.0),
        );
    }

    public function testForceEllipsisTrimsTrailingWhitespaceBeforeAppendingTheMarker(): void
    {
        $metrics = new StandardFontMetrics();
        $truncator = new TextOverflowTruncator($metrics);

        self::assertSame(
            'word...',
            $truncator->forceEllipsis('word   ', $metrics->measureString('F1', 10.0, 'word ...'), 'F1', 10.0),
        );
    }

    public function testForceEllipsisFallsBackToOnlyTheMarkerWhenNothingFits(): void
    {
        $truncator = new TextOverflowTruncator(new StandardFontMetrics());

        self::assertSame('...', $truncator->forceEllipsis('text', 1.0, 'F1', 10.0));
    }

    public function testForceEllipsisReturnsOnlyTheMarkerForInvalidUtf8Input(): void
    {
        $truncator = new TextOverflowTruncator(new StandardFontMetrics());

        self::assertSame('...', $truncator->forceEllipsis("\xc3\x28", 10.0, 'F1', 10.0));
    }

    public function testForceEllipsisUsesSuffixWidthWhenCheckingFit(): void
    {
        $metrics = new StandardFontMetrics();
        $truncator = new TextOverflowTruncator($metrics);

        self::assertSame(
            'i...',
            $truncator->forceEllipsis('iW', $metrics->measureString('F1', 10.0, 'i...'), 'F1', 10.0),
        );
    }
}
