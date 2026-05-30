<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Layout;

use LibreSign\XObjectTemplate\Css\InlineStyleParser;
use LibreSign\XObjectTemplate\Layout\LayoutStyleResolver;
use LibreSign\XObjectTemplate\Layout\TextBoxLayouter;
use LibreSign\XObjectTemplate\Pdf\StandardFontMetrics;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TextBoxLayouterTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string}>
     */
    public static function alignmentProvider(): iterable
    {
        yield 'center' => ['center'];
        yield 'right' => ['right'];
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function justifyStyleProvider(): iterable
    {
        yield 'normalized justify' => ['font-size:10;text-align:justify'];
        yield 'uppercase trimmed justify' => ['font-size:10;text-align: JUSTIFY '];
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function autoHyphenationStyleProvider(): iterable
    {
        yield 'normalized auto hyphenation' => ['font-size:10;hyphens:auto'];
        yield 'uppercase trimmed auto hyphenation' => ['font-size:10;hyphens: AUTO '];
    }

    /**
     * @return iterable<string, array{inlineStyle: string, expectedText: string, expectedTruncated: bool}>
     */
    public static function clipOverflowProvider(): iterable
    {
        yield 'clip with nowrap keeps full text' => [
            'inlineStyle' => 'font-size:10;overflow:hidden;text-overflow:clip;white-space:nowrap;height:12',
            'expectedText' => 'Wrap this text nicely',
            'expectedTruncated' => false,
        ];

        yield 'clip with wrapping truncates without ellipsis' => [
            'inlineStyle' => 'font-size:10;overflow:hidden;text-overflow:clip;height:12',
            'expectedText' => 'Wrap this',
            'expectedTruncated' => true,
        ];
    }

    /**
     * @return iterable<string, array{inlineStyle: string}>
     */
    public static function singleLineEllipsisProvider(): iterable
    {
        yield 'ellipsis with nowrap' => [
            'inlineStyle' => 'font-size:10;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;height:12',
        ];

        yield 'ellipsis with wrapping' => [
            'inlineStyle' => 'font-size:10;overflow:hidden;text-overflow:ellipsis;height:12',
        ];

        yield 'uppercase trimmed overflow and ellipsis' => [
            'inlineStyle' => 'font-size:10;overflow: HIDDEN ;text-overflow: ELLIPSIS ;height:12',
        ];
    }

    /**
     * @return iterable<string, array{
     *     clipBox: array{x: float, y: float, width: float, height: float},
     *     expectedClipBox: array{x: float, y: float, width: float, height: float}
     * }>
     */
    public static function providedClipBoxProvider(): iterable
    {
        yield 'preserves clip box within canvas' => [
            'clipBox' => ['x' => 5.0, 'y' => 6.0, 'width' => 30.0, 'height' => 8.0],
            'expectedClipBox' => ['x' => 5.0, 'y' => 86.0, 'width' => 30.0, 'height' => 8.0],
        ];

        yield 'clamps clip box below canvas to zero' => [
            'clipBox' => ['x' => 5.0, 'y' => 95.0, 'width' => 30.0, 'height' => 8.0],
            'expectedClipBox' => ['x' => 5.0, 'y' => 0.0, 'width' => 30.0, 'height' => 8.0],
        ];
    }

    public function testLayoutWrapsTextIntoMultipleMeasuredLines(): void
    {
        $result = $this->layoutWrappedText();

        self::assertCount(2, $result['lines']);
        self::assertSame('Wrap this', $result['lines'][0]->text);
        self::assertSame('text', $result['lines'][1]->text);
        self::assertEqualsWithDelta(24.0, $result['consumedHeight'], 0.0001);
        self::assertFalse($result['truncated']);
    }

    public function testLayoutUsesPdfCanvasCoordinatesForWrappedLines(): void
    {
        $result = $this->layoutWrappedText();

        self::assertCount(2, $result['lines']);
        self::assertSame(88.0, $result['lines'][0]->y);
        self::assertSame(76.0, $result['lines'][1]->y);
    }

    public function testLayoutClampsLineYBelowCanvasToZero(): void
    {
        $layouter = new TextBoxLayouter(new LayoutStyleResolver(), new StandardFontMetrics());
        $style = (new InlineStyleParser())->parse('font-size:10;white-space:nowrap');

        $result = $layouter->layout(
            'Wrap this',
            $style,
            ['x' => 0.0, 'y' => 95.0, 'width' => 90.0, 'height' => 0.0],
            100.0,
        );

        self::assertCount(1, $result['lines']);
        self::assertSame(0.0, $result['lines'][0]->y);
    }

    public function testLayoutTreatsWhitespaceOnlyInputAsEmptyAndNotTruncated(): void
    {
        $layouter = new TextBoxLayouter(new LayoutStyleResolver(), new StandardFontMetrics());
        $style = (new InlineStyleParser())->parse('font-size:10;overflow:hidden;text-overflow:ellipsis;height:12');

        $result = $layouter->layout(
            '   ',
            $style,
            ['x' => 0.0, 'y' => 0.0, 'width' => 50.0, 'height' => 12.0],
            100.0,
        );

        self::assertSame([], $result['lines']);
        self::assertSame(0.0, $result['consumedHeight']);
        self::assertFalse($result['truncated']);
    }

    #[DataProvider('justifyStyleProvider')]
    public function testLayoutAddsWordSpacingOnlyToIntermediateJustifiedLines(string $inlineStyle): void
    {
        $this->assertIntermediateJustifiedLineSpacing($inlineStyle);
    }

    #[DataProvider('autoHyphenationStyleProvider')]
    public function testLayoutHyphenatesLongWordsWhenAutoIsEnabled(string $inlineStyle): void
    {
        $this->assertAutoHyphenation($inlineStyle);
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

    public function testLayoutSupportsUppercaseTrimmedNowrapWhiteSpace(): void
    {
        $layouter = new TextBoxLayouter(new LayoutStyleResolver(), new StandardFontMetrics());
        $style = (new InlineStyleParser())->parse('font-size:10;hyphens:none;white-space: NOWRAP ');

        $result = $layouter->layout(
            'Wrap this text nicely',
            $style,
            ['x' => 0.0, 'y' => 0.0, 'width' => 35.0, 'height' => 0.0],
            100.0,
        );

        self::assertCount(1, $result['lines']);
        self::assertSame('Wrap this text nicely', $result['lines'][0]->text);
        self::assertFalse($result['truncated']);
    }

    #[DataProvider('providedClipBoxProvider')]
    public function testLayoutTransformsProvidedClipBoxForCanvasCoordinates(
        array $clipBox,
        array $expectedClipBox,
    ): void {
        $layouter = new TextBoxLayouter(new LayoutStyleResolver(), new StandardFontMetrics());
        $style = (new InlineStyleParser())->parse('font-size:10;overflow:hidden;text-overflow:ellipsis;height:12');

        $result = $layouter->layout(
            'Wrap this text nicely',
            $style,
            ['x' => 0.0, 'y' => 0.0, 'width' => 50.0, 'height' => 12.0],
            100.0,
            $clipBox,
        );

        self::assertCount(1, $result['lines']);
        self::assertSame($expectedClipBox, $result['lines'][0]->clipBox);
    }

    public function testLayoutDoesNotTruncateWhenOverflowIsHiddenButHeightIsZero(): void
    {
        $layouter = new TextBoxLayouter(new LayoutStyleResolver(), new StandardFontMetrics());
        $style = (new InlineStyleParser())->parse('font-size:10;overflow:hidden;text-overflow:ellipsis;height:0');

        $result = $layouter->layout(
            'Wrap this text nicely',
            $style,
            ['x' => 0.0, 'y' => 0.0, 'width' => 50.0, 'height' => 0.0],
            100.0,
        );

        self::assertCount(2, $result['lines']);
        self::assertFalse($result['truncated']);
        self::assertNull($result['lines'][0]->clipBox);
    }

    #[DataProvider('clipOverflowProvider')]
    public function testLayoutHandlesClipOverflowWithoutAddingEllipsis(
        string $inlineStyle,
        string $expectedText,
        bool $expectedTruncated,
    ): void {
        $layouter = new TextBoxLayouter(new LayoutStyleResolver(), new StandardFontMetrics());
        $style = (new InlineStyleParser())->parse($inlineStyle);

        $result = $layouter->layout(
            'Wrap this text nicely',
            $style,
            ['x' => 0.0, 'y' => 0.0, 'width' => 50.0, 'height' => 12.0],
            100.0,
        );

        self::assertCount(1, $result['lines']);
        self::assertSame($expectedTruncated, $result['truncated']);
        self::assertSame($expectedText, $result['lines'][0]->text);
    }

    #[DataProvider('singleLineEllipsisProvider')]
    public function testLayoutTruncatesWithEllipsisUsingSupportedStyleVariants(string $inlineStyle): void
    {
        $this->assertSingleLineEllipsisTruncation($inlineStyle);
    }

    public function testLayoutDoesNotAddEllipsisWhenNowrapLineFitsExactly(): void
    {
        $fontMetrics = new StandardFontMetrics();
        $layouter = new TextBoxLayouter(new LayoutStyleResolver(), $fontMetrics);
        $style = (new InlineStyleParser())->parse(
            'font-size:10;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;height:12',
        );
        $text = 'Wrap this';
        $boxWidth = $fontMetrics->measureString('Helvetica', 10.0, $text);

        $result = $layouter->layout(
            $text,
            $style,
            ['x' => 0.0, 'y' => 0.0, 'width' => $boxWidth, 'height' => 12.0],
            100.0,
        );

        self::assertCount(1, $result['lines']);
        self::assertFalse($result['truncated']);
        self::assertSame($text, $result['lines'][0]->text);
    }

    public function testLayoutTruncatesToTwoVisibleLinesWhenHeightRoundsUp(): void
    {
        $layouter = new TextBoxLayouter(new LayoutStyleResolver(), new StandardFontMetrics());
        $style = (new InlineStyleParser())->parse(
            'font-size:10;overflow:hidden;text-overflow:ellipsis;height:13',
        );

        $result = $layouter->layout(
            'Wrap this text nicely again please',
            $style,
            ['x' => 0.0, 'y' => 0.0, 'width' => 50.0, 'height' => 13.0],
            100.0,
        );

        self::assertCount(2, $result['lines']);
        self::assertSame('Wrap this', $result['lines'][0]->text);
        self::assertStringEndsWith('...', $result['lines'][1]->text);
        self::assertTrue($result['truncated']);
    }

    public function testLayoutComputesExactWordSpacingForJustifiedIntermediateLine(): void
    {
        $fontMetrics = new StandardFontMetrics();
        $layouter = new TextBoxLayouter(new LayoutStyleResolver(), $fontMetrics);
        $style = (new InlineStyleParser())->parse('font-size:10;text-align:justify');

        $result = $layouter->layout(
            'Wrap this text nicely',
            $style,
            ['x' => 0.0, 'y' => 0.0, 'width' => 50.0, 'height' => 0.0],
            100.0,
        );

        $expectedSpacing = (50.0 - $fontMetrics->measureString('Helvetica', 10.0, 'Wrap this')) / 1.0;

        self::assertCount(2, $result['lines']);
        self::assertSame('Wrap this', $result['lines'][0]->text);
        self::assertEqualsWithDelta($expectedSpacing, $result['lines'][0]->wordSpacing, 0.0001);
    }

    public function testLayoutKeepsWordSpacingAtZeroWhenJustifiedLineHasNoSpaces(): void
    {
        $layouter = new TextBoxLayouter(new LayoutStyleResolver(), new StandardFontMetrics());
        $style = (new InlineStyleParser())->parse('font-size:10;text-align:justify;hyphens:none');

        $result = $layouter->layout(
            'Supercalifragilistic text',
            $style,
            ['x' => 0.0, 'y' => 0.0, 'width' => 35.0, 'height' => 0.0],
            100.0,
        );

        self::assertCount(2, $result['lines']);
        self::assertSame('Supercalifragilistic', $result['lines'][0]->text);
        self::assertSame(0.0, $result['lines'][0]->wordSpacing);
    }

    public function testLayoutDividesJustifiedExtraWidthAcrossMultipleSpaces(): void
    {
        $fontMetrics = new StandardFontMetrics();
        $layouter = new TextBoxLayouter(new LayoutStyleResolver(), $fontMetrics);
        $style = (new InlineStyleParser())->parse('font-size:10;text-align:justify');
        $firstLine = 'Wrap this text';
        $boxWidth = $fontMetrics->measureString('Helvetica', 10.0, $firstLine) + 6.0;

        $result = $layouter->layout(
            'Wrap this text nicely',
            $style,
            ['x' => 0.0, 'y' => 0.0, 'width' => $boxWidth, 'height' => 0.0],
            100.0,
        );

        self::assertCount(2, $result['lines']);
        self::assertSame($firstLine, $result['lines'][0]->text);
        self::assertEqualsWithDelta(3.0, $result['lines'][0]->wordSpacing, 0.0001);
    }

    public function testLayoutKeepsWordSpacingAtZeroWhenJustifiedLineFitsExactly(): void
    {
        $fontMetrics = new StandardFontMetrics();
        $layouter = new TextBoxLayouter(new LayoutStyleResolver(), $fontMetrics);
        $style = (new InlineStyleParser())->parse('font-size:10;text-align:justify');
        $firstLine = 'Wrap this text';
        $boxWidth = $fontMetrics->measureString('Helvetica', 10.0, $firstLine);

        $result = $layouter->layout(
            'Wrap this text nicely',
            $style,
            ['x' => 0.0, 'y' => 0.0, 'width' => $boxWidth, 'height' => 0.0],
            100.0,
        );

        self::assertCount(2, $result['lines']);
        self::assertSame($firstLine, $result['lines'][0]->text);
        self::assertSame(0.0, $result['lines'][0]->wordSpacing);
    }

    #[DataProvider('alignmentProvider')]
    public function testLayoutResolvesAlignedXPositions(string $alignment): void
    {
        $fontMetrics = new StandardFontMetrics();
        $layouter = new TextBoxLayouter(new LayoutStyleResolver(), $fontMetrics);
        $style = (new InlineStyleParser())->parse(sprintf('font-size:10;text-align:%s;white-space:nowrap', $alignment));

        $result = $layouter->layout(
            'Wrap this',
            $style,
            ['x' => 10.0, 'y' => 0.0, 'width' => 90.0, 'height' => 0.0],
            100.0,
        );

        $line = $result['lines'][0];
        $lineWidth = $fontMetrics->measureString($line->fontAlias, $line->fontSize, $line->text);

        $expectedX = match ($alignment) {
            'center' => 10.0 + ((90.0 - $lineWidth) / 2.0),
            'right' => 10.0 + (90.0 - $lineWidth),
        };

        self::assertEqualsWithDelta($expectedX, $line->x, 0.0001);
    }

    #[DataProvider('alignmentProvider')]
    public function testLayoutClampsOverflowedAlignedLineToBoxX(string $alignment): void
    {
        $layouter = new TextBoxLayouter(new LayoutStyleResolver(), new StandardFontMetrics());
        $style = (new InlineStyleParser())->parse(sprintf('font-size:10;text-align:%s;white-space:nowrap', $alignment));

        $result = $layouter->layout(
            'Wrap this text nicely',
            $style,
            ['x' => 7.0, 'y' => 0.0, 'width' => 10.0, 'height' => 0.0],
            100.0,
        );

        self::assertCount(1, $result['lines']);
        self::assertSame(7.0, $result['lines'][0]->x);
    }

    private function assertAutoHyphenation(string $inlineStyle): void
    {
        $layouter = new TextBoxLayouter(new LayoutStyleResolver(), new StandardFontMetrics());
        $style = (new InlineStyleParser())->parse($inlineStyle);

        $result = $layouter->layout(
            'Supercalifragilistic',
            $style,
            ['x' => 0.0, 'y' => 0.0, 'width' => 35.0, 'height' => 0.0],
            100.0,
        );

        self::assertGreaterThan(1, count($result['lines']));
        self::assertStringEndsWith('-', $result['lines'][0]->text);
    }

    private function assertIntermediateJustifiedLineSpacing(string $inlineStyle): void
    {
        $layouter = new TextBoxLayouter(new LayoutStyleResolver(), new StandardFontMetrics());
        $style = (new InlineStyleParser())->parse($inlineStyle);

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

    /**
     * @return array{lines: list<\LibreSign\XObjectTemplate\Layout\LayoutLine>, consumedHeight: float, truncated: bool}
     */
    private function layoutWrappedText(): array
    {
        $layouter = new TextBoxLayouter(new LayoutStyleResolver(), new StandardFontMetrics());
        $style = (new InlineStyleParser())->parse('font-size:10');

        return $layouter->layout(
            'Wrap this text',
            $style,
            ['x' => 0.0, 'y' => 0.0, 'width' => 50.0, 'height' => 0.0],
            100.0,
        );
    }

    private function assertSingleLineEllipsisTruncation(string $inlineStyle): void
    {
        $layouter = new TextBoxLayouter(new LayoutStyleResolver(), new StandardFontMetrics());
        $style = (new InlineStyleParser())->parse($inlineStyle);

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
