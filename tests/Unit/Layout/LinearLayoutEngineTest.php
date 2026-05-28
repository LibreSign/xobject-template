<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Layout;

use LibreSign\XObjectTemplate\Html\Node;
use LibreSign\XObjectTemplate\Layout\LinearLayoutEngine;
use PHPUnit\Framework\TestCase;

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
                    'src' => '/fixture/signature.png',
                    'style' => 'width:0;height:-10',
                ],
            ),
        ], 20.0, 15.0);

        self::assertCount(1, $result->images);
        self::assertSame('/fixture/signature.png', $result->images[0]->source);
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
}
