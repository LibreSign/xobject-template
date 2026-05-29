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

    public function testLayoutSupportsTrimmedUppercaseDisplayFlexWithoutOtherFlexHints(): void
    {
        $renderer = $this->createRenderer();

        $result = $renderer->layout([
            new Node(
                tag: 'div',
                text: '',
                attributes: ['style' => 'display: FLEX ;width:40;height:40'],
                children: [
                    $this->imageNode('/left.png', 'width:10;height:20'),
                    $this->imageNode('/right.png', 'width:10;height:20'),
                ],
            ),
        ], 40.0, 40.0);

        self::assertCount(2, $result->images);
        self::assertSame(0.0, $result->images[0]->x);
        self::assertSame(10.0, $result->images[1]->x);
        self::assertSame(20.0, $result->images[0]->y);
        self::assertSame(20.0, $result->images[1]->y);
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

    public function testLayoutUsesAutoHeightForDecoratedBlockContainersWithoutClipping(): void
    {
        $renderer = $this->createRenderer();

        $result = $renderer->layout([
            new Node(
                tag: 'div',
                text: 'Auto height',
                attributes: ['style' => 'width:100;height:12;padding:10;background-color:#abcdef;font-size:10'],
            ),
        ], 120.0, 80.0);

        self::assertCount(1, $result->decorations);
        self::assertSame(32.0, $result->decorations[0]->height);
        self::assertSame(48.0, $result->decorations[0]->y);
    }

    public function testLayoutTrimsDecorationColorsFromInlineStyles(): void
    {
        $renderer = $this->createRenderer();

        $result = $renderer->layout([
            new Node(
                tag: 'div',
                text: '',
                attributes: [
                    'style' => 'width:100;height:20;background-color:  #abcdef  ;border-color:  #123456  ;'
                        . 'border-width:2',
                ],
            ),
        ], 120.0, 80.0);

        self::assertCount(1, $result->decorations);
        self::assertSame('#abcdef', $result->decorations[0]->fillColor);
        self::assertSame('#123456', $result->decorations[0]->strokeColor);
    }

    public function testLayoutCreatesBorderOnlyDecorationWhenStrokeHasPositiveWidth(): void
    {
        $renderer = $this->createRenderer();

        $result = $renderer->layout([
            new Node(
                tag: 'div',
                text: '',
                attributes: ['style' => 'width:100;height:20;border-color:#123456;border-width:2'],
            ),
        ], 120.0, 80.0);

        self::assertCount(1, $result->decorations);
        self::assertNull($result->decorations[0]->fillColor);
        self::assertSame('#123456', $result->decorations[0]->strokeColor);
        self::assertSame(2.0, $result->decorations[0]->strokeWidth);
    }

    public function testLayoutSkipsBorderOnlyDecorationWhenStrokeWidthIsZero(): void
    {
        $renderer = $this->createRenderer();

        $result = $renderer->layout([
            new Node(
                tag: 'div',
                text: '',
                attributes: ['style' => 'width:100;height:20;border-color:#123456;border-width:0'],
            ),
        ], 120.0, 80.0);

        self::assertCount(0, $result->decorations);
    }

    public function testLayoutSkipsDecorationsForZeroWidthFlexItems(): void
    {
        $renderer = $this->createRenderer();

        $result = $renderer->layout([
            new Node(
                tag: 'div',
                text: '',
                attributes: ['style' => 'display:flex;width:20;height:20'],
                children: [
                    new Node(
                        tag: 'div',
                        text: '',
                        attributes: ['style' => 'width:0;height:10;background-color:#abcdef'],
                    ),
                ],
            ),
        ], 120.0, 80.0);

        self::assertCount(0, $result->decorations);
    }

    public function testLayoutSkipsDecorationsForZeroHeightBlocks(): void
    {
        $renderer = $this->createRenderer();

        $result = $renderer->layout([
            new Node(
                tag: 'div',
                text: '',
                attributes: ['style' => 'width:20;height:0;background-color:#abcdef'],
            ),
        ], 120.0, 80.0);

        self::assertCount(0, $result->decorations);
    }

    public function testLayoutClampsDecorationYAtZeroWhenBoxExtendsBelowCanvas(): void
    {
        $renderer = $this->createRenderer();

        $result = $renderer->layout([
            new Node(
                tag: 'div',
                text: '',
                attributes: ['style' => 'position:absolute;left:0;top:70;width:20;height:20;background-color:#abcdef'],
            ),
        ], 120.0, 80.0);

        self::assertCount(1, $result->decorations);
        self::assertSame(0.0, $result->decorations[0]->y);
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

    public function testLayoutAppliesClipBoxesWhenOverflowHiddenIsTrimmedAndUppercase(): void
    {
        $renderer = $this->createRenderer();

        $result = $renderer->layout([
            new Node(
                tag: 'div',
                text: 'Wrap this text nicely',
                attributes: [
                    'style' => 'width:50;height:12;overflow: HIDDEN ;text-overflow:ellipsis;font-size:10',
                ],
            ),
        ], 120.0, 80.0);

        self::assertCount(1, $result->lines);
        self::assertSame(['x' => 0.0, 'y' => 68.0, 'width' => 50.0, 'height' => 12.0], $result->lines[0]->clipBox);
    }

    public function testLayoutDoesNotApplyClipBoxesWhenOverflowHiddenContainerWidthIsZero(): void
    {
        $renderer = $this->createRenderer();

        $result = $renderer->layout([
            new Node(
                tag: 'div',
                text: '',
                attributes: ['style' => 'display:flex;width:20;height:20'],
                children: [
                    new Node(
                        tag: 'div',
                        text: '',
                        attributes: ['style' => 'overflow:hidden;width:0;height:10'],
                        children: [$this->imageNode('/clip-width-zero.png', 'width:10;height:10')],
                    ),
                ],
            ),
        ], 120.0, 80.0);

        self::assertCount(1, $result->images);
        self::assertNull($result->images[0]->clipBox);
    }

    public function testLayoutDoesNotApplyClipBoxesWhenOverflowHiddenContainerHeightIsZero(): void
    {
        $renderer = $this->createRenderer();

        $result = $renderer->layout([
            new Node(
                tag: 'div',
                text: '',
                attributes: ['style' => 'width:20;height:0;overflow:hidden'],
                children: [$this->imageNode('/clip-height-zero.png', 'width:10;height:10')],
            ),
        ], 120.0, 80.0);

        self::assertCount(1, $result->images);
        self::assertNull($result->images[0]->clipBox);
    }

    public function testLayoutIntersectsNestedTrimmedUppercaseHiddenClipBoxes(): void
    {
        $renderer = $this->createRenderer();

        $result = $renderer->layout([
            new Node(
                tag: 'div',
                text: '',
                attributes: ['style' => 'overflow: HIDDEN ;width:50;height:40'],
                children: [
                    new Node(
                        tag: 'div',
                        text: '',
                        attributes: ['style' => 'margin:10 0 0 30;overflow: HIDDEN ;width:40;height:30'],
                        children: [$this->imageNode('/nested-clip.png', 'width:40;height:30')],
                    ),
                ],
            ),
        ], 120.0, 80.0);

        self::assertCount(1, $result->images);
        self::assertSame(['x' => 30.0, 'y' => 40.0, 'width' => 20.0, 'height' => 30.0], $result->images[0]->clipBox);
    }

    public function testLayoutKeepsZeroWidthForNestedHiddenClipBoxIntersection(): void
    {
        $renderer = $this->createRenderer();

        $result = $renderer->layout([
            new Node(
                tag: 'div',
                text: '',
                attributes: ['style' => 'overflow:hidden;width:20;height:40'],
                children: [
                    new Node(
                        tag: 'div',
                        text: '',
                        attributes: ['style' => 'margin:0 0 0 30;overflow:hidden;width:10;height:10'],
                        children: [$this->imageNode('/zero-width-clip.png', 'width:10;height:10')],
                    ),
                ],
            ),
        ], 120.0, 80.0);

        self::assertCount(1, $result->images);
        self::assertSame(['x' => 30.0, 'y' => 70.0, 'width' => 0.0, 'height' => 10.0], $result->images[0]->clipBox);
    }

    public function testLayoutKeepsZeroHeightForNestedHiddenClipBoxIntersection(): void
    {
        $renderer = $this->createRenderer();

        $result = $renderer->layout([
            new Node(
                tag: 'div',
                text: '',
                attributes: ['style' => 'overflow:hidden;width:40;height:20'],
                children: [
                    new Node(
                        tag: 'div',
                        text: '',
                        attributes: ['style' => 'margin:30 0 0 0;overflow:hidden;width:10;height:10'],
                        children: [$this->imageNode('/zero-height-clip.png', 'width:10;height:10')],
                    ),
                ],
            ),
        ], 120.0, 80.0);

        self::assertCount(1, $result->images);
        self::assertSame(['x' => 0.0, 'y' => 50.0, 'width' => 10.0, 'height' => 0.0], $result->images[0]->clipBox);
    }

    public function testLayoutClampsClipBoxYAtZeroWhenContainerExtendsBelowCanvas(): void
    {
        $renderer = $this->createRenderer();

        $result = $renderer->layout([
            new Node(
                tag: 'div',
                text: '',
                attributes: ['style' => 'position:absolute;left:0;top:70;overflow:hidden;width:20;height:20'],
                children: [$this->imageNode('/below-canvas-clip.png', 'width:20;height:20')],
            ),
        ], 120.0, 80.0);

        self::assertCount(1, $result->images);
        self::assertSame(['x' => 0.0, 'y' => 0.0, 'width' => 20.0, 'height' => 20.0], $result->images[0]->clipBox);
    }

    public function testLayoutKeepsFixedHeightAndDecorationForClippedFlexContainers(): void
    {
        $renderer = $this->createRenderer();

        $result = $renderer->layout([
            new Node(
                tag: 'div',
                text: '',
                attributes: [
                    'style' => 'display:flex;overflow:hidden;width:100;height:60;padding:2;'
                        . 'background-color:#abcdef',
                ],
                children: [
                    $this->imageNode('/flex.png', 'width:10;height:80'),
                ],
            ),
        ], 120.0, 100.0);

        self::assertCount(1, $result->decorations);
        self::assertSame(60.0, $result->decorations[0]->height);
        self::assertSame(40.0, $result->decorations[0]->y);
    }

    public function testLayoutUsesAccumulatedConsumedHeightForLaterPercentageSizedNodes(): void
    {
        $renderer = $this->createRenderer();

        $result = $renderer->layout([
            $this->textNode('First'),
            $this->textNode('Second'),
            $this->imageNode('/remaining.png', 'width:10;height:50%'),
        ], 100.0, 100.0);

        self::assertCount(1, $result->images);
        self::assertSame(10.0, $result->images[0]->width);
        self::assertSame(38.0, $result->images[0]->height);
        self::assertSame(38.0, $result->images[0]->y);
    }

    public function testLayoutKeepsCollapsedRemainingHeightAtZeroForLaterPercentageImage(): void
    {
        $renderer = $this->createRenderer();

        $result = $renderer->layout([
            $this->textNode('Tall line'),
            $this->imageNode('/collapsed.png', 'width:10;height:50%'),
        ], 100.0, 10.0);

        self::assertCount(1, $result->images);
        self::assertSame(10.0, $result->images[0]->width);
        self::assertSame(32.0, $result->images[0]->height);
        self::assertSame(0.0, $result->images[0]->y);
    }

    public function testLayoutKeepsZeroCanvasDimensionsAtZeroForAbsolutePercentageImage(): void
    {
        $renderer = $this->createRenderer();

        $result = $renderer->layout([
            $this->imageNode('/zero-canvas.png', 'position:absolute;left:0;top:0;width:100%;height:100%'),
        ], 0.0, 0.0);

        self::assertCount(1, $result->images);
        self::assertSame(32.0, $result->images[0]->width);
        self::assertSame(32.0, $result->images[0]->height);
        self::assertSame(0.0, $result->images[0]->x);
        self::assertSame(0.0, $result->images[0]->y);
    }

    public function testLayoutFallsBackToDefaultImageSizeWhenResolvedDimensionsAreZero(): void
    {
        $renderer = $this->createRenderer();

        $result = $renderer->layout([
            $this->imageNode('/zero-dimension.png', 'width:0;height:0'),
        ], 100.0, 100.0);

        self::assertCount(1, $result->images);
        self::assertSame(32.0, $result->images[0]->width);
        self::assertSame(32.0, $result->images[0]->height);
        self::assertSame(68.0, $result->images[0]->y);
    }

    public function testLayoutAssignsSequentialAliasesToRenderedImages(): void
    {
        $renderer = $this->createRenderer();

        $result = $renderer->layout([
            $this->imageNode('/first.png', 'width:10;height:10'),
            $this->imageNode('/second.png', 'width:10;height:10'),
        ], 100.0, 100.0);

        self::assertCount(2, $result->images);
        self::assertSame('Im0', $result->images[0]->alias);
        self::assertSame('Im1', $result->images[1]->alias);
    }

    public function testLayoutKeepsZeroCanvasHeightWhenRenderingTinyText(): void
    {
        $renderer = $this->createRenderer();

        $result = $renderer->layout([
            $this->textNode('i', 'font-size:0.1'),
        ], 10.0, 0.0);

        self::assertCount(1, $result->lines);
        self::assertSame(0.0, $result->lines[0]->y);
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
