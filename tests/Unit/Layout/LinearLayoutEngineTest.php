<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Layout;

use LibreSign\XObjectTemplate\Css\InlineStyleParser;
use LibreSign\XObjectTemplate\Html\Node;
use LibreSign\XObjectTemplate\Layout\LinearLayoutEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

final class LinearLayoutEngineTest extends TestCase
{
    public function testLayoutSupportsNestedNodesImagesAndStyles(): void
    {
        $engine = new LinearLayoutEngine();
        $nodes = [
            new Node(
                tag: 'div',
                text: '',
                attributes: [
                    'style' => 'font-size:12;font-family:Times New Roman;font-weight:700;text-align:right;width:200',
                ],
                children: [
                    new Node(tag: 'span', text: 'Approved', attributes: []),
                    new Node(
                        tag: 'img',
                        text: '',
                        attributes: [
                            'src' => '/fixture/sign.png',
                            'style' => 'width:20px;height:20px',
                        ],
                    ),
                ],
            ),
        ];

        $result = $engine->layout($nodes, 240.0, 90.0);

        self::assertCount(1, $result->lines);
        self::assertCount(1, $result->images);
        self::assertSame('Approved', $result->lines[0]->text);
        self::assertSame('F1', $result->lines[0]->fontAlias);
        self::assertSame(8.0, $result->lines[0]->x);
        self::assertSame(78.0, $result->lines[0]->y);
        self::assertSame('/fixture/sign.png', $result->images[0]->source);
        self::assertSame('Im0', $result->images[0]->alias);
        self::assertEqualsWithDelta(51.0, $result->images[0]->y, 0.0001);
        self::assertEqualsWithDelta(15.0, $result->images[0]->width, 0.0001);
        self::assertEqualsWithDelta(15.0, $result->images[0]->height, 0.0001);
    }

    public function testLayoutUsesBreakNodesToMoveToTheNextLine(): void
    {
        $engine = new LinearLayoutEngine();
        $result = $engine->layout([
            new Node(tag: 'p', text: 'First line', attributes: []),
            new Node(tag: 'br', text: '', attributes: []),
            new Node(tag: 'span', text: 'Second line', attributes: []),
        ], 240.0, 90.0);

        self::assertCount(2, $result->lines);
        self::assertSame('First line', $result->lines[0]->text);
        self::assertSame('Second line', $result->lines[1]->text);
        self::assertLessThan($result->lines[0]->y, $result->lines[1]->y);
    }

    public function testLayoutSupportsRightAndCenterAlignmentWithFallbackWidth(): void
    {
        $engine = new LinearLayoutEngine();

        $result = $engine->layout([
            new Node(
                tag: 'p',
                text: 'Right',
                attributes: [
                    'style' => 'text-align:right;margin:1 2 3 4;padding:5 6 7 8;width:0;font-size:10',
                ],
            ),
            new Node(
                tag: 'p',
                text: 'Center',
                attributes: [
                    'style' => 'text-align:center;margin:1 2 3 4;padding:5 6 7 8;width:0;font-size:10',
                ],
            ),
        ], 100.0, 100.0);

        self::assertCount(2, $result->lines);
        self::assertSame('Right', $result->lines[0]->text);
        self::assertSame('Center', $result->lines[1]->text);
        self::assertEqualsWithDelta(84.0, $result->lines[0]->x, 0.0001);
        self::assertEqualsWithDelta(52.0, $result->lines[1]->x, 0.0001);
        self::assertEqualsWithDelta(82.0, $result->lines[0]->y, 0.0001);
    }

    public function testLayoutTreatsNonPositiveImageDimensionsAsDefaultsAndClampsToCanvas(): void
    {
        $engine = new LinearLayoutEngine();

        $result = $engine->layout([
            new Node(
                tag: 'img',
                text: '',
                attributes: [
                    'src' => '/fixture/example-image.png',
                    'style' => 'width:0;height:-10',
                ],
            ),
        ], 20.0, 15.0);

        self::assertCount(1, $result->images);
        self::assertSame('/fixture/example-image.png', $result->images[0]->source);
        self::assertSame('Im0', $result->images[0]->alias);
        self::assertEqualsWithDelta(20.0, $result->images[0]->width, 0.0001);
        self::assertEqualsWithDelta(15.0, $result->images[0]->height, 0.0001);
        self::assertEqualsWithDelta(0.0, $result->images[0]->y, 0.0001);
    }

    public function testLayoutResolvesQuotedFontFamilyAndNumericBoldWeight(): void
    {
        $engine = new LinearLayoutEngine();

        $result = $engine->layout([
            new Node(
                tag: 'p',
                text: 'Times bold',
                attributes: ['style' => 'font-family: "Times New Roman" ; font-weight:600; font-size:10'],
            ),
            new Node(
                tag: 'p',
                text: 'Helvetica regular',
                attributes: ['style' => 'font-family: Helvetica; font-weight:599; font-size:10'],
            ),
        ], 120.0, 90.0);

        self::assertCount(2, $result->lines);
        self::assertSame('F4', $result->lines[0]->fontAlias);
        self::assertSame('F1', $result->lines[1]->fontAlias);
    }

    public function testLayoutImageFlowAdvancesCursorAndAliasForSubsequentNodes(): void
    {
        $engine = new LinearLayoutEngine();

        $result = $engine->layout([
            new Node(
                tag: 'img',
                text: '',
                attributes: [
                    'src' => '/a.png',
                    'style' => 'width:10px;height:8px;margin:0 0 3 0;padding:0 0 4 0',
                ],
            ),
            new Node(
                tag: 'img',
                text: '',
                attributes: [
                    'src' => '/b.png',
                    'style' => 'width:10px;height:8px;margin:0 0 3 0;padding:0 0 4 0',
                ],
            ),
            new Node(tag: 'p', text: 'After images', attributes: ['style' => 'font-size:10']),
        ], 100.0, 100.0);

        self::assertCount(2, $result->images);
        self::assertSame('Im0', $result->images[0]->alias);
        self::assertSame('Im1', $result->images[1]->alias);
        self::assertEqualsWithDelta(82.0, $result->images[0]->y, 0.0001);
        self::assertEqualsWithDelta(67.0, $result->images[1]->y, 0.0001);
        self::assertCount(1, $result->lines);
        self::assertSame('After images', $result->lines[0]->text);
        self::assertEqualsWithDelta(58.0, $result->lines[0]->y, 0.0001);
    }

    public function testLayoutUsesCssSpacingShorthandSemanticsForTwoThreeAndFourValues(): void
    {
        $engine = new LinearLayoutEngine();

        $result = $engine->layout([
            new Node(tag: 'p', text: 'Two', attributes: ['style' => 'font-size:10;margin:1 2']),
            new Node(tag: 'p', text: 'Three', attributes: ['style' => 'font-size:10;margin:1 2 3']),
            new Node(tag: 'p', text: 'Four', attributes: ['style' => 'font-size:10;margin:1 2 3 4']),
        ], 100.0, 100.0);

        self::assertCount(3, $result->lines);
        self::assertEqualsWithDelta(10.0, $result->lines[0]->x, 0.0001);
        self::assertEqualsWithDelta(10.0, $result->lines[1]->x, 0.0001);
        self::assertEqualsWithDelta(12.0, $result->lines[2]->x, 0.0001);
        self::assertEqualsWithDelta(87.0, $result->lines[0]->y, 0.0001);
        self::assertEqualsWithDelta(73.0, $result->lines[1]->y, 0.0001);
        self::assertEqualsWithDelta(57.0, $result->lines[2]->y, 0.0001);
    }

    public function testLayoutTreatsZeroImageHeightAsDefaultDimension(): void
    {
        $engine = new LinearLayoutEngine();

        $result = $engine->layout([
            new Node(tag: 'img', text: '', attributes: ['src' => '/zero.png', 'style' => 'width:12;height:0']),
        ], 200.0, 120.0);

        self::assertCount(1, $result->images);
        self::assertEqualsWithDelta(12.0, $result->images[0]->width, 0.0001);
        self::assertEqualsWithDelta(32.0, $result->images[0]->height, 0.0001);
    }

    public function testLayoutSupportsAbsolutelyPositionedImagesWithoutAdvancingFlow(): void
    {
        $engine = new LinearLayoutEngine();

        $result = $engine->layout([
            new Node(
                tag: 'div',
                text: '',
                attributes: ['style' => 'width:200;height:100'],
                children: [
                    new Node(
                        tag: 'img',
                        text: '',
                        attributes: [
                            'src' => '/fixture/background.png',
                            'style' => 'position:absolute;left:0;top:0;width:100%;height:100%',
                        ],
                    ),
                    new Node(tag: 'span', text: 'Foreground text', attributes: ['style' => 'font-size:10']),
                ],
            ),
        ], 200.0, 100.0);

        self::assertCount(1, $result->images);
        self::assertCount(1, $result->lines);
        self::assertEqualsWithDelta(0.0, $result->images[0]->x, 0.0001);
        self::assertEqualsWithDelta(0.0, $result->images[0]->y, 0.0001);
        self::assertEqualsWithDelta(200.0, $result->images[0]->width, 0.0001);
        self::assertEqualsWithDelta(100.0, $result->images[0]->height, 0.0001);
        self::assertSame('Foreground text', $result->lines[0]->text);
        self::assertEqualsWithDelta(88.0, $result->lines[0]->y, 0.0001);
    }

    public function testLayoutSupportsFlexRowsWithPercentageColumns(): void
    {
        $engine = new LinearLayoutEngine();

        $result = $engine->layout([
            new Node(
                tag: 'div',
                text: '',
                attributes: ['style' => 'display:flex;flex-direction:row;width:200;height:100'],
                children: [
                    new Node(
                        tag: 'div',
                        text: '',
                        attributes: ['style' => 'width:50%;height:100%'],
                        children: [
                            new Node(tag: 'span', text: 'Left column', attributes: ['style' => 'font-size:10']),
                        ],
                    ),
                    new Node(
                        tag: 'div',
                        text: '',
                        attributes: ['style' => 'width:50%;height:100%'],
                        children: [
                            new Node(tag: 'span', text: 'Right column', attributes: ['style' => 'font-size:10']),
                        ],
                    ),
                ],
            ),
        ], 200.0, 100.0);

        self::assertCount(2, $result->lines);
        self::assertSame('Left column', $result->lines[0]->text);
        self::assertSame('Right column', $result->lines[1]->text);
        self::assertEqualsWithDelta(0.0, $result->lines[0]->x, 0.0001);
        self::assertEqualsWithDelta(100.0, $result->lines[1]->x, 0.0001);
        self::assertEqualsWithDelta(88.0, $result->lines[0]->y, 0.0001);
        self::assertEqualsWithDelta(88.0, $result->lines[1]->y, 0.0001);
    }

    public function testLayoutSupportsFlexCenteringForImages(): void
    {
        $engine = new LinearLayoutEngine();

        $result = $engine->layout([
            new Node(
                tag: 'div',
                text: '',
                attributes: [
                    'style' => 'display:flex;justify-content:center;align-items:center;width:200;height:100',
                ],
                children: [
                    new Node(
                        tag: 'img',
                        text: '',
                        attributes: ['src' => '/fixture/center.png', 'style' => 'width:80;height:40'],
                    ),
                ],
            ),
        ], 200.0, 100.0);

        self::assertCount(1, $result->images);
        self::assertEqualsWithDelta(60.0, $result->images[0]->x, 0.0001);
        self::assertEqualsWithDelta(30.0, $result->images[0]->y, 0.0001);
        self::assertEqualsWithDelta(80.0, $result->images[0]->width, 0.0001);
        self::assertEqualsWithDelta(40.0, $result->images[0]->height, 0.0001);
    }

    public function testConstructorKeepsProvidedInlineStyleParserInstance(): void
    {
        $styleParser = new InlineStyleParser();
        $engine = new LinearLayoutEngine($styleParser);

        $property = new ReflectionProperty($engine, 'styleParser');

        self::assertSame($styleParser, $property->getValue($engine));
    }

    public function testLayoutNormalizesTrimmedSpacingFontAndAlignmentValues(): void
    {
        $engine = new LinearLayoutEngine();

        $result = $engine->layout([
            new Node(
                tag: 'p',
                text: 'Trimmed',
                attributes: [
                    'style' => 'margin:  4px  8px ; padding: 2px  6px 4px 8px ; '
                        . 'font-size: 10PX; text-align: RIGHT ; color:#123456',
                ],
            ),
        ], 100.0, 90.0);

        self::assertCount(1, $result->lines);
        self::assertSame('Trimmed', $result->lines[0]->text);
        self::assertEqualsWithDelta(81.5, $result->lines[0]->x, 0.0001);
        self::assertEqualsWithDelta(73.5, $result->lines[0]->y, 0.0001);
        self::assertEqualsWithDelta(7.5, $result->lines[0]->fontSize, 0.0001);
        self::assertSame('#123456', $result->lines[0]->rgbColor);
    }

    public function testLayoutTrimsTextUsesDefaultLineHeightMultiplierAndAdvancesBreaks(): void
    {
        $engine = new LinearLayoutEngine();

        $result = $engine->layout([
            new Node(tag: 'span', text: '  Trim me  ', attributes: ['style' => 'font-size:10']),
            new Node(tag: 'br', text: '', attributes: []),
            new Node(tag: 'span', text: 'Next', attributes: ['style' => 'font-size:10']),
        ], 120.0, 90.0);

        self::assertCount(2, $result->lines);
        self::assertSame('Trim me', $result->lines[0]->text);
        self::assertSame('Next', $result->lines[1]->text);
        self::assertEqualsWithDelta(78.0, $result->lines[0]->y, 0.0001);
        self::assertEqualsWithDelta(54.0, $result->lines[1]->y, 0.0001);
    }

    public function testLayoutClampsRightAlignmentAndLineYToZeroOnTinyCanvas(): void
    {
        $engine = new LinearLayoutEngine();

        $result = $engine->layout([
            new Node(
                tag: 'span',
                text: 'Tiny',
                attributes: ['style' => 'text-align:right;width:0;margin:0;padding:0;font-size:10'],
            ),
        ], 4.0, 6.0);

        self::assertCount(1, $result->lines);
        self::assertEqualsWithDelta(0.0, $result->lines[0]->x, 0.0001);
        self::assertEqualsWithDelta(0.0, $result->lines[0]->y, 0.0001);
    }

    public function testLayoutPrefersExplicitLineHeightWhenItExceedsFontDefault(): void
    {
        $engine = new LinearLayoutEngine();

        $result = $engine->layout([
            new Node(tag: 'span', text: 'First', attributes: ['style' => 'font-size:10;line-height:30']),
            new Node(tag: 'br', text: '', attributes: []),
            new Node(tag: 'span', text: 'Second', attributes: ['style' => 'font-size:10']),
        ], 120.0, 120.0);

        self::assertCount(2, $result->lines);
        self::assertEqualsWithDelta(108.0, $result->lines[0]->y, 0.0001);
        self::assertEqualsWithDelta(66.0, $result->lines[1]->y, 0.0001);
    }

    public function testLayoutKeepsZeroFallbackWidthForCenteredTinyCanvas(): void
    {
        $engine = new LinearLayoutEngine();

        $result = $engine->layout([
            new Node(
                tag: 'span',
                text: 'Center',
                attributes: ['style' => 'text-align:center;width:0;font-size:10'],
            ),
        ], 0.0, 20.0);

        self::assertCount(1, $result->lines);
        self::assertEqualsWithDelta(0.0, $result->lines[0]->x, 0.0001);
    }

    public function testLayoutClampsNegativeAvailableWidthToZero(): void
    {
        $engine = new LinearLayoutEngine();

        $result = $engine->layout([
            new Node(
                tag: 'span',
                text: 'Negative width',
                attributes: ['style' => 'text-align:center;width:0;font-size:10'],
            ),
        ], -1.0, 20.0);

        self::assertCount(1, $result->lines);
        self::assertEqualsWithDelta(0.0, $result->lines[0]->x, 0.0001);
    }

    public function testLayoutSubtractsBottomSpacingFromFollowingLinePosition(): void
    {
        $engine = new LinearLayoutEngine();

        $result = $engine->layout([
            new Node(tag: 'span', text: 'First', attributes: ['style' => 'font-size:10;margin:0 0 5;padding:0 0 7']),
            new Node(tag: 'span', text: 'Second', attributes: ['style' => 'font-size:10']),
        ], 100.0, 100.0);

        self::assertCount(2, $result->lines);
        self::assertEqualsWithDelta(88.0, $result->lines[0]->y, 0.0001);
        self::assertEqualsWithDelta(64.0, $result->lines[1]->y, 0.0001);
    }

    public function testLayoutUsesThreeValueSpacingRightSlotForHorizontalPositioning(): void
    {
        $engine = new LinearLayoutEngine();

        $result = $engine->layout([
            new Node(
                tag: 'span',
                text: 'Three values',
                attributes: ['style' => 'font-size:10;text-align:right;margin:1 20 3;width:0'],
            ),
        ], 100.0, 100.0);

        self::assertCount(1, $result->lines);
        self::assertEqualsWithDelta(72.0, $result->lines[0]->x, 0.0001);
    }

    public function testLayoutRecognizesTrimmedUppercaseBoldTokens(): void
    {
        $engine = new LinearLayoutEngine();

        $result = $engine->layout([
            new Node(
                tag: 'span',
                text: 'Bold text',
                attributes: ['style' => 'font-size:10;font-family:Courier;font-weight: BOLD '],
            ),
            new Node(
                tag: 'span',
                text: 'Numeric bold',
                attributes: ['style' => 'font-size:10;font-family:Helvetica;font-weight: 600 '],
            ),
        ], 120.0, 100.0);

        self::assertCount(2, $result->lines);
        self::assertSame('F6', $result->lines[0]->fontAlias);
        self::assertSame('F2', $result->lines[1]->fontAlias);
    }

    #[DataProvider('nestedTraversalProvider')]
    public function testLayoutKeepsDepthFirstTraversalOrderForNestedNodes(array $nodes, array $expectedTexts): void
    {
        $engine = new LinearLayoutEngine();

        $result = $engine->layout($nodes, 240.0, 90.0);

        self::assertSame($expectedTexts, array_map(static fn ($line): string => $line->text, $result->lines));
    }

    public function testLayoutHandlesDeeplyNestedTreeWithoutDroppingLeafText(): void
    {
        $engine = new LinearLayoutEngine();

        $depth = 120;
        $leaf = new Node(tag: 'span', text: 'Deep leaf', attributes: ['style' => 'font-size:10']);
        for ($i = 0; $i < $depth; ++$i) {
            $leaf = new Node(tag: 'div', text: '', attributes: [], children: [$leaf]);
        }

        $result = $engine->layout([$leaf], 240.0, 90.0);

        self::assertCount(1, $result->lines);
        self::assertSame('Deep leaf', $result->lines[0]->text);
    }

    public function testParseBoxSpacingReturnsZeroSlotsForWhitespaceOnlyInput(): void
    {
        $engine = new LinearLayoutEngine();
        $method = new ReflectionMethod($engine, 'parseBoxSpacing');

        /** @var array{top: float, right: float, bottom: float, left: float} $spacing */
        $spacing = $method->invoke($engine, '   ');

        self::assertSame(
            ['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0],
            $spacing,
        );
    }

    /**
     * @return iterable<string, array{nodes: list<Node>, expectedTexts: list<string>}>
     */
    public static function nestedTraversalProvider(): iterable
    {
        yield 'root before children and sibling after subtree' => [
            'nodes' => [
                new Node(
                    tag: 'div',
                    text: 'A',
                    attributes: ['style' => 'font-size:10'],
                    children: [
                        new Node(tag: 'span', text: 'B', attributes: ['style' => 'font-size:10']),
                        new Node(
                            tag: 'span',
                            text: 'C',
                            attributes: ['style' => 'font-size:10'],
                            children: [
                                new Node(tag: 'span', text: 'D', attributes: ['style' => 'font-size:10']),
                            ],
                        ),
                    ],
                ),
                new Node(tag: 'p', text: 'E', attributes: ['style' => 'font-size:10']),
            ],
            'expectedTexts' => ['A', 'B', 'C', 'D', 'E'],
        ];

        yield 'multiple roots preserve insertion order' => [
            'nodes' => [
                new Node(tag: 'span', text: 'First', attributes: ['style' => 'font-size:10']),
                new Node(tag: 'span', text: 'Second', attributes: ['style' => 'font-size:10']),
            ],
            'expectedTexts' => ['First', 'Second'],
        ];
    }
}
