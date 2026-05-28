<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Layout;

use LibreSign\XObjectTemplate\Css\InlineStyleParser;
use LibreSign\XObjectTemplate\Html\Node;
use LibreSign\XObjectTemplate\Layout\LayoutStyleResolver;
use LibreSign\XObjectTemplate\Layout\StructuredFlexLayoutPlanner;
use PHPUnit\Framework\TestCase;

final class StructuredFlexLayoutPlannerTest extends TestCase
{
    public function testNormalizeDirectionAndResolveGapUseExpectedAxis(): void
    {
        $parser = new InlineStyleParser();
        $planner = new StructuredFlexLayoutPlanner(new LayoutStyleResolver());
        $style = $parser->parse('gap:10%');

        self::assertSame('row', $planner->normalizeDirection('ROW'));
        self::assertSame('column', $planner->normalizeDirection('column'));
        self::assertSame('column', $planner->normalizeDirection(' COLUMN '));
        self::assertSame('row', $planner->normalizeDirection('  unexpected  '));
        self::assertSame(
            20.0,
            $planner->resolveGap($style, 'row', ['x' => 0.0, 'y' => 0.0, 'width' => 200.0, 'height' => 50.0]),
        );
        self::assertSame(
            5.0,
            $planner->resolveGap($style, 'column', ['x' => 0.0, 'y' => 0.0, 'width' => 200.0, 'height' => 50.0]),
        );
    }

    public function testMeasureItemUsesImageAndTextFallbacks(): void
    {
        $parser = new InlineStyleParser();
        $planner = new StructuredFlexLayoutPlanner(new LayoutStyleResolver());

        $imageSize = $planner->measureItem(
            new Node(tag: 'img', text: '', attributes: ['src' => '/icon.png']),
            $parser->parse(''),
            ['x' => 0.0, 'y' => 0.0, 'width' => 100.0, 'height' => 40.0],
        );
        $textSize = $planner->measureItem(
            new Node(tag: 'span', text: 'Label', attributes: []),
            $parser->parse('font-size:10'),
            ['x' => 0.0, 'y' => 0.0, 'width' => 100.0, 'height' => 40.0],
        );
        $trimmedTextSize = $planner->measureItem(
            new Node(tag: 'span', text: '  Label  ', attributes: []),
            $parser->parse('font-size:10'),
            ['x' => 0.0, 'y' => 0.0, 'width' => 100.0, 'height' => 40.0],
        );
        $containerFallbackSize = $planner->measureItem(
            new Node(tag: 'div', text: '', attributes: []),
            $parser->parse(''),
            ['x' => 0.0, 'y' => 0.0, 'width' => 100.0, 'height' => 40.0],
        );

        self::assertSame(['width' => 32.0, 'height' => 32.0], $imageSize);
        self::assertSame(['width' => 24.46, 'height' => 12.0], $textSize);
        self::assertSame(['width' => 24.46, 'height' => 12.0], $trimmedTextSize);
        self::assertSame(['width' => 0.0, 'height' => 40.0], $containerFallbackSize);
    }

    public function testCalculateMetricsSupportsSpaceBetween(): void
    {
        $planner = new StructuredFlexLayoutPlanner(new LayoutStyleResolver());
        $parser = new InlineStyleParser();
        $items = [
            [
                'node' => new Node('div', '', []),
                'style' => $parser->parse(''),
                'size' => ['width' => 50.0, 'height' => 20.0],
            ],
            [
                'node' => new Node('div', '', []),
                'style' => $parser->parse(''),
                'size' => ['width' => 50.0, 'height' => 30.0],
            ],
        ];

        $metrics = $planner->calculateMetrics(
            $items,
            'row',
            'space-between',
            0.0,
            ['x' => 0.0, 'y' => 0.0, 'width' => 200.0, 'height' => 100.0],
        );

        self::assertSame(100.0, $metrics['gap']);
        self::assertSame(0.0, $metrics['mainAxisOffset']);
        self::assertSame(200.0, $metrics['totalMainAxisSize']);
        self::assertSame(30.0, $metrics['crossAxisSize']);
        self::assertSame(100.0, $metrics['crossContainerSize']);
    }

    public function testCalculateMetricsKeepsConfiguredGapForSingleSpaceBetweenItem(): void
    {
        $planner = new StructuredFlexLayoutPlanner(new LayoutStyleResolver());
        $parser = new InlineStyleParser();
        $items = [[
            'node' => new Node('div', '', []),
            'style' => $parser->parse(''),
            'size' => ['width' => 50.0, 'height' => 20.0],
        ]];

        $metrics = $planner->calculateMetrics(
            $items,
            'row',
            'space-between',
            7.0,
            ['x' => 0.0, 'y' => 0.0, 'width' => 200.0, 'height' => 100.0],
        );

        self::assertSame(7.0, $metrics['gap']);
        self::assertSame(50.0, $metrics['totalMainAxisSize']);
        self::assertSame(20.0, $metrics['crossAxisSize']);
    }

    public function testCreateChildBoxSupportsRowAndColumnLayouts(): void
    {
        $planner = new StructuredFlexLayoutPlanner(new LayoutStyleResolver());
        $item = [
            'node' => new Node('div', '', []),
            'style' => (new InlineStyleParser())->parse(''),
            'size' => ['width' => 50.0, 'height' => 20.0],
        ];
        $contentBox = ['x' => 10.0, 'y' => 20.0, 'width' => 200.0, 'height' => 100.0];

        $rowBox = $planner->createChildBox($item, 'row', 'center', $contentBox, 100.0, 30.0);
        $columnBox = $planner->createChildBox($item, 'column', 'flex-end', $contentBox, 200.0, 15.0);

        self::assertSame(['x' => 40.0, 'y' => 60.0, 'width' => 50.0, 'height' => 20.0], $rowBox);
        self::assertSame(['x' => 160.0, 'y' => 35.0, 'width' => 50.0, 'height' => 20.0], $columnBox);
        self::assertSame(57.0, $planner->advanceCursor($item, 'row', 7.0));
        self::assertSame(27.0, $planner->advanceCursor($item, 'column', 7.0));
    }

    public function testCreateChildBoxFallsBackToContainerDimensionWhenItemCrossSizeIsZero(): void
    {
        $planner = new StructuredFlexLayoutPlanner(new LayoutStyleResolver());
        $contentBox = ['x' => 10.0, 'y' => 20.0, 'width' => 200.0, 'height' => 100.0];

        $rowBox = $planner->createChildBox(
            [
                'node' => new Node('div', '', []),
                'style' => (new InlineStyleParser())->parse(''),
                'size' => ['width' => 50.0, 'height' => 0.0],
            ],
            'row',
            'center',
            $contentBox,
            100.0,
            30.0,
        );
        $columnBox = $planner->createChildBox(
            [
                'node' => new Node('div', '', []),
                'style' => (new InlineStyleParser())->parse(''),
                'size' => ['width' => 0.0, 'height' => 20.0],
            ],
            'column',
            'flex-end',
            $contentBox,
            200.0,
            15.0,
        );

        self::assertSame(100.0, $rowBox['height']);
        self::assertSame(200.0, $columnBox['width']);
    }

    public function testCreateChildBoxClampsOversizedAlignmentOffsetsToZero(): void
    {
        $planner = new StructuredFlexLayoutPlanner(new LayoutStyleResolver());
        $contentBox = ['x' => 10.0, 'y' => 20.0, 'width' => 200.0, 'height' => 100.0];

        $rowBox = $planner->createChildBox(
            [
                'node' => new Node('div', '', []),
                'style' => (new InlineStyleParser())->parse(''),
                'size' => ['width' => 50.0, 'height' => 150.0],
            ],
            'row',
            'center',
            $contentBox,
            100.0,
            30.0,
        );
        $columnBox = $planner->createChildBox(
            [
                'node' => new Node('div', '', []),
                'style' => (new InlineStyleParser())->parse(''),
                'size' => ['width' => 250.0, 'height' => 20.0],
            ],
            'column',
            'flex-end',
            $contentBox,
            200.0,
            15.0,
        );

        self::assertSame(20.0, $rowBox['y']);
        self::assertSame(10.0, $columnBox['x']);
    }

    public function testMeasureItemTreatsWhitespaceOnlyTextAsEmpty(): void
    {
        $planner = new StructuredFlexLayoutPlanner(new LayoutStyleResolver());

        $size = $planner->measureItem(
            new Node(tag: 'span', text: '   ', attributes: []),
            (new InlineStyleParser())->parse('font-size:10'),
            ['x' => 0.0, 'y' => 0.0, 'width' => 100.0, 'height' => 0.0],
        );

        self::assertSame(['width' => 0.0, 'height' => 0.0], $size);
    }

    public function testCalculateMetricsSupportsThreeItemSpaceBetweenAndEmptyCollections(): void
    {
        $planner = new StructuredFlexLayoutPlanner(new LayoutStyleResolver());
        $parser = new InlineStyleParser();
        $items = [
            [
                'node' => new Node('div', '', []),
                'style' => $parser->parse(''),
                'size' => ['width' => 20.0, 'height' => 10.0],
            ],
            [
                'node' => new Node('div', '', []),
                'style' => $parser->parse(''),
                'size' => ['width' => 20.0, 'height' => 10.0],
            ],
            [
                'node' => new Node('div', '', []),
                'style' => $parser->parse(''),
                'size' => ['width' => 20.0, 'height' => 10.0],
            ],
        ];

        $threeItemMetrics = $planner->calculateMetrics(
            $items,
            'row',
            'space-between',
            0.0,
            ['x' => 0.0, 'y' => 0.0, 'width' => 200.0, 'height' => 100.0],
        );
        $emptyMetrics = $planner->calculateMetrics(
            [],
            'row',
            'flex-start',
            7.0,
            ['x' => 0.0, 'y' => 0.0, 'width' => 200.0, 'height' => 100.0],
        );

        self::assertSame(70.0, $threeItemMetrics['gap']);
        self::assertSame(200.0, $threeItemMetrics['totalMainAxisSize']);
        self::assertSame(0.0, $emptyMetrics['totalMainAxisSize']);
        self::assertSame(0.0, $emptyMetrics['crossAxisSize']);
    }

    public function testCalculateMetricsClampsCenterAndFlexEndOffsetsToZero(): void
    {
        $planner = new StructuredFlexLayoutPlanner(new LayoutStyleResolver());
        $parser = new InlineStyleParser();
        $items = [[
            'node' => new Node('div', '', []),
            'style' => $parser->parse(''),
            'size' => ['width' => 250.0, 'height' => 20.0],
        ]];

        $centerMetrics = $planner->calculateMetrics(
            $items,
            'row',
            'center',
            0.0,
            ['x' => 0.0, 'y' => 0.0, 'width' => 200.0, 'height' => 100.0],
        );
        $flexEndMetrics = $planner->calculateMetrics(
            $items,
            'row',
            'flex-end',
            0.0,
            ['x' => 0.0, 'y' => 0.0, 'width' => 200.0, 'height' => 100.0],
        );

        self::assertSame(0.0, $centerMetrics['mainAxisOffset']);
        self::assertSame(0.0, $flexEndMetrics['mainAxisOffset']);
    }
}
