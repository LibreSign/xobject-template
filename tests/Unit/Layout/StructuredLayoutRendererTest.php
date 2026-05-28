<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Layout;

use LibreSign\XObjectTemplate\Css\InlineStyleParser;
use LibreSign\XObjectTemplate\Html\Node;
use LibreSign\XObjectTemplate\Layout\LayoutDecoration;
use LibreSign\XObjectTemplate\Layout\LayoutStyleResolver;
use LibreSign\XObjectTemplate\Layout\StructuredLayoutRenderer;
use PHPUnit\Framework\TestCase;

final class StructuredLayoutRendererTest extends TestCase
{
    public function testLayoutKeepsAbsoluteNodesOutOfFlowAndAccumulatesFlowHeights(): void
    {
        $renderer = $this->createRenderer();

        $result = $renderer->layout([
            $this->imageNode('/bg.png', 'position:absolute;left:0;top:0;width:100;height:20'),
            $this->textNode('  First  ', 'font-size:10;margin:5 0 7 0'),
            $this->textNode('Second'),
        ], 100.0, 100.0);

        self::assertCount(1, $result->images);
        self::assertCount(2, $result->lines);
        self::assertSame('/bg.png', $result->images[0]->source);
        self::assertSame(0.0, $result->images[0]->x);
        self::assertSame(80.0, $result->images[0]->y);
        self::assertSame(100.0, $result->images[0]->width);
        self::assertSame(20.0, $result->images[0]->height);
        self::assertSame('First', $result->lines[0]->text);
        self::assertSame(83.0, $result->lines[0]->y);
        self::assertSame('Second', $result->lines[1]->text);
        self::assertSame(64.0, $result->lines[1]->y);
    }

    public function testLayoutSupportsTrimmedUppercaseFlexAlignmentAndGapSpacing(): void
    {
        $renderer = $this->createRenderer();

        $result = $renderer->layout([
            new Node(
                tag: 'div',
                text: '',
                attributes: [
                    'style' => 'display:flex;justify-content: CENTER ;align-items: FLEX-END ;gap:10;'
                        . 'width:100;height:40',
                ],
                children: [
                    $this->imageNode('/left.png', 'width:10;height:20'),
                    $this->imageNode('/right.png', 'width:10;height:20'),
                ],
            ),
        ], 100.0, 100.0);

        self::assertCount(2, $result->images);
        self::assertSame(35.0, $result->images[0]->x);
        self::assertSame(55.0, $result->images[1]->x);
        self::assertSame(60.0, $result->images[0]->y);
        self::assertSame(60.0, $result->images[1]->y);
    }

    public function testLayoutUsesAutoFlexHeightToPositionFollowingSiblings(): void
    {
        $renderer = $this->createRenderer();

        $result = $renderer->layout([
            new Node(
                tag: 'div',
                text: '',
                attributes: ['style' => 'display:flex;gap:10;width:100;padding:2 0 3 0'],
                children: [
                    $this->imageNode('/first.png', 'width:10;height:20'),
                    $this->imageNode('/second.png', 'width:10;height:20'),
                ],
            ),
            $this->textNode('Below'),
        ], 100.0, 100.0);

        self::assertCount(2, $result->images);
        self::assertCount(1, $result->lines);
        self::assertSame(0.0, $result->images[0]->x);
        self::assertSame(20.0, $result->images[1]->x);
        self::assertSame(78.0, $result->images[0]->y);
        self::assertSame(78.0, $result->images[1]->y);
        self::assertSame('Below', $result->lines[0]->text);
        self::assertSame(63.0, $result->lines[0]->y);
    }

    public function testLayoutAccumulatesParentTextAndChildHeightBeforeFollowingNodes(): void
    {
        $renderer = $this->createRenderer();

        $result = $renderer->layout([
            new Node(
                tag: 'div',
                text: 'Parent',
                attributes: ['style' => 'font-size:10'],
                children: [$this->textNode('Child')],
            ),
            $this->textNode('After'),
        ], 100.0, 100.0);

        self::assertCount(3, $result->lines);
        self::assertSame('Parent', $result->lines[0]->text);
        self::assertSame(88.0, $result->lines[0]->y);
        self::assertSame('Child', $result->lines[1]->text);
        self::assertSame(76.0, $result->lines[1]->y);
        self::assertSame('After', $result->lines[2]->text);
        self::assertSame(64.0, $result->lines[2]->y);
    }

    public function testLayoutCreatesVectorDecorationsForBackgroundAndBorders(): void
    {
        $renderer = $this->createRenderer();

        $result = $renderer->layout([
            new Node(
                tag: 'div',
                text: 'Decorated',
                attributes: [
                    'style' => 'width:100;height:40;padding:4;background-color:#abcdef;'
                        . 'border-color:#123456;border-width:2;border-radius:6;font-size:10',
                ],
            ),
        ], 120.0, 80.0);

        self::assertCount(1, $result->decorations);
        self::assertInstanceOf(LayoutDecoration::class, $result->decorations[0]);
        self::assertSame('#abcdef', $result->decorations[0]->fillColor);
        self::assertSame('#123456', $result->decorations[0]->strokeColor);
        self::assertSame(2.0, $result->decorations[0]->strokeWidth);
        self::assertSame(6.0, $result->decorations[0]->borderRadius);
        self::assertSame(100.0, $result->decorations[0]->width);
        self::assertSame(40.0, $result->decorations[0]->height);
        self::assertSame(40.0, $result->decorations[0]->y);
    }

    public function testLayoutAppliesClipBoxesWhenOverflowIsHidden(): void
    {
        $renderer = $this->createRenderer();

        $result = $renderer->layout([
            new Node(
                tag: 'div',
                text: 'Wrap this text nicely',
                attributes: [
                    'style' => 'width:50;height:12;overflow:hidden;text-overflow:ellipsis;font-size:10',
                ],
            ),
        ], 120.0, 80.0);

        self::assertCount(1, $result->lines);
        self::assertStringEndsWith('...', $result->lines[0]->text);
        self::assertSame(['x' => 0.0, 'y' => 68.0, 'width' => 50.0, 'height' => 12.0], $result->lines[0]->clipBox);
    }

    private function createRenderer(): StructuredLayoutRenderer
    {
        return new StructuredLayoutRenderer(new InlineStyleParser(), new LayoutStyleResolver());
    }

    private function imageNode(string $source, string $style): Node
    {
        return new Node(
            tag: 'img',
            text: '',
            attributes: ['src' => $source, 'style' => $style],
        );
    }

    private function textNode(string $text, string $style = 'font-size:10'): Node
    {
        return new Node(
            tag: 'span',
            text: $text,
            attributes: ['style' => $style],
        );
    }
}
