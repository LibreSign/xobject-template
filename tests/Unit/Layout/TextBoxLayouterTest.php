<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Layout;

use LibreSign\XObjectTemplate\Css\InlineStyleParser;
use LibreSign\XObjectTemplate\Layout\LayoutStyleResolver;
use LibreSign\XObjectTemplate\Layout\TextBoxLayouter;
use LibreSign\XObjectTemplate\Pdf\StandardFontMetrics;
use PHPUnit\Framework\TestCase;

final class TextBoxLayouterTest extends TestCase
{
    public function testLayoutWrapsTextIntoMultipleMeasuredLines(): void
    {
        $layouter = new TextBoxLayouter(new LayoutStyleResolver(), new StandardFontMetrics());
        $style = (new InlineStyleParser())->parse('font-size:10');

        $result = $layouter->layout(
            'Wrap this text',
            $style,
            ['x' => 0.0, 'y' => 0.0, 'width' => 50.0, 'height' => 0.0],
            100.0,
        );

        self::assertCount(2, $result['lines']);
        self::assertSame('Wrap this', $result['lines'][0]->text);
        self::assertSame('text', $result['lines'][1]->text);
        self::assertEqualsWithDelta(24.0, $result['consumedHeight'], 0.0001);
        self::assertFalse($result['truncated']);
    }

    public function testLayoutAddsWordSpacingOnlyToIntermediateJustifiedLines(): void
    {
        $layouter = new TextBoxLayouter(new LayoutStyleResolver(), new StandardFontMetrics());
        $style = (new InlineStyleParser())->parse('font-size:10;text-align:justify');

        $result = $layouter->layout(
            'Wrap this text nicely',
            $style,
            ['x' => 0.0, 'y' => 0.0, 'width' => 50.0, 'height' => 0.0],
            100.0,
        );

        self::assertCount(2, $result['lines']);
        self::assertGreaterThan(0.0, $result['lines'][0]->wordSpacing);
        self::assertSame(0.0, $result['lines'][1]->wordSpacing);
    }

    public function testLayoutHyphenatesLongWordsWhenAutoIsEnabled(): void
    {
        $layouter = new TextBoxLayouter(new LayoutStyleResolver(), new StandardFontMetrics());
        $style = (new InlineStyleParser())->parse('font-size:10;hyphens:auto');

        $result = $layouter->layout(
            'Supercalifragilistic',
            $style,
            ['x' => 0.0, 'y' => 0.0, 'width' => 35.0, 'height' => 0.0],
            100.0,
        );

        self::assertGreaterThan(1, count($result['lines']));
        self::assertStringEndsWith('-', $result['lines'][0]->text);
    }

    public function testLayoutUsesManualSoftHyphenHints(): void
    {
        $layouter = new TextBoxLayouter(new LayoutStyleResolver(), new StandardFontMetrics());
        $style = (new InlineStyleParser())->parse('font-size:10;hyphens:manual');

        $result = $layouter->layout(
            "hyphen\u{00AD}ation",
            $style,
            ['x' => 0.0, 'y' => 0.0, 'width' => 35.0, 'height' => 0.0],
            100.0,
        );

        self::assertCount(2, $result['lines']);
        self::assertSame('hyphen-', $result['lines'][0]->text);
        self::assertSame('ation', $result['lines'][1]->text);
    }

    public function testLayoutKeepsLongWordOnSingleLineWhenHyphenationIsDisabled(): void
    {
        $layouter = new TextBoxLayouter(new LayoutStyleResolver(), new StandardFontMetrics());
        $style = (new InlineStyleParser())->parse('font-size:10;hyphens:none;white-space:nowrap');

        $result = $layouter->layout(
            'Supercalifragilistic',
            $style,
            ['x' => 0.0, 'y' => 0.0, 'width' => 35.0, 'height' => 0.0],
            100.0,
        );

        self::assertCount(1, $result['lines']);
        self::assertSame('Supercalifragilistic', $result['lines'][0]->text);
        self::assertFalse($result['truncated']);
    }

    public function testLayoutTruncatesWithEllipsisWhenOverflowIsHidden(): void
    {
        $layouter = new TextBoxLayouter(new LayoutStyleResolver(), new StandardFontMetrics());
        $style = (new InlineStyleParser())->parse(
            'font-size:10;overflow:hidden;text-overflow:ellipsis;height:12',
        );

        $result = $layouter->layout(
            'Wrap this text nicely',
            $style,
            ['x' => 0.0, 'y' => 0.0, 'width' => 50.0, 'height' => 12.0],
            100.0,
        );

        self::assertCount(1, $result['lines']);
        self::assertTrue($result['truncated']);
        self::assertStringEndsWith('...', $result['lines'][0]->text);
        self::assertSame(['x' => 0.0, 'y' => 88.0, 'width' => 50.0, 'height' => 12.0], $result['lines'][0]->clipBox);
    }
}
