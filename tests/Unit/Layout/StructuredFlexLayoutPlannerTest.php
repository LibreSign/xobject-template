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

        self::assertSame(['width' => 32.0, 'height' => 32.0], $imageSize);
        self::assertSame(['width' => 0.0, 'height' => 12.0], $textSize);
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
}
