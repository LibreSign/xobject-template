<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Layout;

use LibreSign\XObjectTemplate\Css\InlineStyleParser;
use LibreSign\XObjectTemplate\Html\Node;
use LibreSign\XObjectTemplate\Layout\LayoutStyleResolver;
use LibreSign\XObjectTemplate\Layout\StructuredFlexLayoutPlanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StructuredFlexLayoutPlannerTest extends TestCase
{
    /**
     * @return iterable<string, array{direction: string, expectedDirection: string}>
     */
    public static function normalizedDirectionProvider(): iterable
    {
        yield 'uppercase row normalizes to row' => [
            'direction' => 'ROW',
            'expectedDirection' => 'row',
        ];

        yield 'column stays column' => [
            'direction' => 'column',
            'expectedDirection' => 'column',
        ];

        yield 'trimmed column stays column' => [
            'direction' => ' COLUMN ',
            'expectedDirection' => 'column',
        ];

        yield 'mixed-case column stays column' => [
            'direction' => 'CoLuMn',
            'expectedDirection' => 'column',
        ];

        yield 'trimmed row stays row' => [
            'direction' => ' row ',
            'expectedDirection' => 'row',
        ];

        yield 'unexpected value falls back to row' => [
            'direction' => '  unexpected  ',
            'expectedDirection' => 'row',
        ];
    }

    /**
     * @return iterable<string, array{
     *     inlineStyle: string,
     *     direction: string,
     *     contentBox: array{x: float, y: float, width: float, height: float},
     *     expectedGap: float
     * }>
     */
    public static function resolveGapProvider(): iterable
    {
        yield 'row gap uses container width' => [
            'inlineStyle' => 'gap:10%',
            'direction' => 'row',
            'contentBox' => ['x' => 0.0, 'y' => 0.0, 'width' => 200.0, 'height' => 50.0],
            'expectedGap' => 20.0,
        ];

        yield 'column gap uses container height' => [
            'inlineStyle' => 'gap:10%',
            'direction' => 'column',
            'contentBox' => ['x' => 0.0, 'y' => 0.0, 'width' => 200.0, 'height' => 50.0],
            'expectedGap' => 5.0,
        ];

        yield 'pixel gap converts through point normalization' => [
            'inlineStyle' => 'gap:16PX',
            'direction' => 'row',
            'contentBox' => ['x' => 0.0, 'y' => 0.0, 'width' => 200.0, 'height' => 50.0],
            'expectedGap' => 12.0,
        ];

        yield 'missing gap defaults to zero' => [
            'inlineStyle' => '',
            'direction' => 'row',
            'contentBox' => ['x' => 0.0, 'y' => 0.0, 'width' => 200.0, 'height' => 50.0],
            'expectedGap' => 0.0,
        ];

        yield 'percentage gap on zero-width row resolves to zero' => [
            'inlineStyle' => 'gap:10%',
            'direction' => 'row',
            'contentBox' => ['x' => 0.0, 'y' => 0.0, 'width' => 0.0, 'height' => 50.0],
            'expectedGap' => 0.0,
        ];
    }

    /**
     * @return iterable<string, array{
     *     node: Node,
     *     inlineStyle: string,
     *     container: array{x: float, y: float, width: float, height: float},
     *     expectedSize: array{width: float, height: float}
     * }>
     */
    public static function measureItemProvider(): iterable
    {
        yield 'image fallback uses default icon size' => [
            'node' => new Node(tag: 'img', text: '', attributes: ['src' => '/icon.png']),
            'inlineStyle' => '',
            'container' => ['x' => 0.0, 'y' => 0.0, 'width' => 100.0, 'height' => 40.0],
            'expectedSize' => ['width' => 32.0, 'height' => 32.0],
        ];

        yield 'plain text uses measured font metrics' => [
            'node' => new Node(tag: 'span', text: 'Label', attributes: []),
            'inlineStyle' => 'font-size:10',
            'container' => ['x' => 0.0, 'y' => 0.0, 'width' => 100.0, 'height' => 40.0],
            'expectedSize' => ['width' => 24.46, 'height' => 12.0],
        ];

        yield 'trimmed text ignores surrounding whitespace' => [
            'node' => new Node(tag: 'span', text: '  Label  ', attributes: []),
            'inlineStyle' => 'font-size:10',
            'container' => ['x' => 0.0, 'y' => 0.0, 'width' => 100.0, 'height' => 40.0],
            'expectedSize' => ['width' => 24.46, 'height' => 12.0],
        ];

        yield 'container fallback preserves container height' => [
            'node' => new Node(tag: 'div', text: '', attributes: []),
            'inlineStyle' => '',
            'container' => ['x' => 0.0, 'y' => 0.0, 'width' => 100.0, 'height' => 40.0],
            'expectedSize' => ['width' => 0.0, 'height' => 40.0],
        ];

        yield 'whitespace only text becomes empty' => [
            'node' => new Node(tag: 'span', text: '   ', attributes: []),
            'inlineStyle' => 'font-size:10',
            'container' => ['x' => 0.0, 'y' => 0.0, 'width' => 100.0, 'height' => 0.0],
            'expectedSize' => ['width' => 0.0, 'height' => 0.0],
        ];

        yield 'visible text still measures when container height is zero' => [
            'node' => new Node(tag: 'span', text: '  Label  ', attributes: []),
            'inlineStyle' => 'font-size:10',
            'container' => ['x' => 0.0, 'y' => 0.0, 'width' => 100.0, 'height' => 0.0],
            'expectedSize' => ['width' => 24.46, 'height' => 12.0],
        ];

        yield 'explicit percentage size uses container references' => [
            'node' => new Node(tag: 'span', text: 'Label', attributes: []),
            'inlineStyle' => 'font-size:10;width:50%;height:25%',
            'container' => ['x' => 0.0, 'y' => 0.0, 'width' => 120.0, 'height' => 40.0],
            'expectedSize' => ['width' => 60.0, 'height' => 10.0],
        ];

        yield 'configured line height can grow measured text height' => [
            'node' => new Node(tag: 'span', text: 'Label', attributes: []),
            'inlineStyle' => 'font-size:10;line-height:18',
            'container' => ['x' => 0.0, 'y' => 0.0, 'width' => 100.0, 'height' => 40.0],
            'expectedSize' => ['width' => 24.46, 'height' => 18.0],
        ];

        yield 'configured line height does not shrink below font default' => [
            'node' => new Node(tag: 'span', text: 'Label', attributes: []),
            'inlineStyle' => 'font-size:10;line-height:8',
            'container' => ['x' => 0.0, 'y' => 0.0, 'width' => 100.0, 'height' => 40.0],
            'expectedSize' => ['width' => 24.46, 'height' => 12.0],
        ];

        yield 'image respects explicit pixel dimensions after point conversion' => [
            'node' => new Node(tag: 'img', text: '', attributes: ['src' => '/icon.png']),
            'inlineStyle' => 'width:40px;height:16PX',
            'container' => ['x' => 0.0, 'y' => 0.0, 'width' => 100.0, 'height' => 40.0],
            'expectedSize' => ['width' => 30.0, 'height' => 12.0],
        ];
    }

    /**
     * @return iterable<string, array{
     *     itemSizes: list<array{width: float, height: float}>,
     *     direction: string,
     *     justifyContent: string,
     *     gap: float,
     *     contentBox: array{x: float, y: float, width: float, height: float},
     *     expectedMetrics: array<string, float>
     * }>
     */
    public static function calculateMetricsProvider(): iterable
    {
        yield 'two items expand gap for row space-between' => [
            'itemSizes' => [
                ['width' => 50.0, 'height' => 20.0],
                ['width' => 50.0, 'height' => 30.0],
            ],
            'direction' => 'row',
            'justifyContent' => 'space-between',
            'gap' => 0.0,
            'contentBox' => ['x' => 0.0, 'y' => 0.0, 'width' => 200.0, 'height' => 100.0],
            'expectedMetrics' => [
                'gap' => 100.0,
                'mainAxisOffset' => 0.0,
                'totalMainAxisSize' => 200.0,
                'crossAxisSize' => 30.0,
                'crossContainerSize' => 100.0,
            ],
        ];

        yield 'single space-between item keeps configured gap' => [
            'itemSizes' => [
                ['width' => 50.0, 'height' => 20.0],
            ],
            'direction' => 'row',
            'justifyContent' => 'space-between',
            'gap' => 7.0,
            'contentBox' => ['x' => 0.0, 'y' => 0.0, 'width' => 200.0, 'height' => 100.0],
            'expectedMetrics' => [
                'gap' => 7.0,
                'totalMainAxisSize' => 50.0,
                'crossAxisSize' => 20.0,
            ],
        ];

        yield 'three items distribute space-between gap' => [
            'itemSizes' => [
                ['width' => 20.0, 'height' => 10.0],
                ['width' => 20.0, 'height' => 10.0],
                ['width' => 20.0, 'height' => 10.0],
            ],
            'direction' => 'row',
            'justifyContent' => 'space-between',
            'gap' => 0.0,
            'contentBox' => ['x' => 0.0, 'y' => 0.0, 'width' => 200.0, 'height' => 100.0],
            'expectedMetrics' => [
                'gap' => 70.0,
                'totalMainAxisSize' => 200.0,
            ],
        ];

        yield 'empty collections keep zero sizes' => [
            'itemSizes' => [],
            'direction' => 'row',
            'justifyContent' => 'flex-start',
            'gap' => 7.0,
            'contentBox' => ['x' => 0.0, 'y' => 0.0, 'width' => 200.0, 'height' => 100.0],
            'expectedMetrics' => [
                'totalMainAxisSize' => 0.0,
                'crossAxisSize' => 0.0,
                'crossContainerSize' => 100.0,
            ],
        ];

        yield 'center alignment offset clamps to zero when content overflows' => [
            'itemSizes' => [
                ['width' => 250.0, 'height' => 20.0],
            ],
            'direction' => 'row',
            'justifyContent' => 'center',
            'gap' => 0.0,
            'contentBox' => ['x' => 0.0, 'y' => 0.0, 'width' => 200.0, 'height' => 100.0],
            'expectedMetrics' => [
                'mainAxisOffset' => 0.0,
            ],
        ];

        yield 'flex-end alignment offset clamps to zero when content overflows' => [
            'itemSizes' => [
                ['width' => 250.0, 'height' => 20.0],
            ],
            'direction' => 'row',
            'justifyContent' => 'flex-end',
            'gap' => 0.0,
            'contentBox' => ['x' => 0.0, 'y' => 0.0, 'width' => 200.0, 'height' => 100.0],
            'expectedMetrics' => [
                'mainAxisOffset' => 0.0,
            ],
        ];

        yield 'center alignment uses half of remaining row space as offset' => [
            'itemSizes' => [
                ['width' => 20.0, 'height' => 10.0],
                ['width' => 30.0, 'height' => 12.0],
            ],
            'direction' => 'row',
            'justifyContent' => 'center',
            'gap' => 0.0,
            'contentBox' => ['x' => 0.0, 'y' => 0.0, 'width' => 100.0, 'height' => 50.0],
            'expectedMetrics' => [
                'mainAxisOffset' => 25.0,
                'totalMainAxisSize' => 50.0,
                'crossAxisSize' => 12.0,
                'crossContainerSize' => 50.0,
            ],
        ];

        yield 'flex-end alignment uses all remaining row space as offset' => [
            'itemSizes' => [
                ['width' => 20.0, 'height' => 10.0],
                ['width' => 30.0, 'height' => 12.0],
            ],
            'direction' => 'row',
            'justifyContent' => 'flex-end',
            'gap' => 0.0,
            'contentBox' => ['x' => 0.0, 'y' => 0.0, 'width' => 100.0, 'height' => 50.0],
            'expectedMetrics' => [
                'mainAxisOffset' => 50.0,
                'totalMainAxisSize' => 50.0,
            ],
        ];

        yield 'column space-between expands vertical gap across remaining space' => [
            'itemSizes' => [
                ['width' => 20.0, 'height' => 10.0],
                ['width' => 15.0, 'height' => 20.0],
            ],
            'direction' => 'column',
            'justifyContent' => 'space-between',
            'gap' => 5.0,
            'contentBox' => ['x' => 0.0, 'y' => 0.0, 'width' => 80.0, 'height' => 100.0],
            'expectedMetrics' => [
                'gap' => 70.0,
                'totalMainAxisSize' => 100.0,
                'crossAxisSize' => 20.0,
                'crossContainerSize' => 80.0,
            ],
        ];
    }

    /**
     * @return iterable<string, array{
     *     itemSize: array{width: float, height: float},
     *     direction: string,
     *     alignItems: string,
     *     contentBox: array{x: float, y: float, width: float, height: float},
     *     crossContainerSize: float,
     *     cursor: float,
     *     expectedChildBox: array<string, float>
     * }>
     */
    public static function createChildBoxProvider(): iterable
    {
        yield 'row layout positions centered child' => [
            'itemSize' => ['width' => 50.0, 'height' => 20.0],
            'direction' => 'row',
            'alignItems' => 'center',
            'contentBox' => ['x' => 10.0, 'y' => 20.0, 'width' => 200.0, 'height' => 100.0],
            'crossContainerSize' => 100.0,
            'cursor' => 30.0,
            'expectedChildBox' => ['x' => 40.0, 'y' => 60.0, 'width' => 50.0, 'height' => 20.0],
        ];

        yield 'column layout positions flex-end child' => [
            'itemSize' => ['width' => 50.0, 'height' => 20.0],
            'direction' => 'column',
            'alignItems' => 'flex-end',
            'contentBox' => ['x' => 10.0, 'y' => 20.0, 'width' => 200.0, 'height' => 100.0],
            'crossContainerSize' => 200.0,
            'cursor' => 15.0,
            'expectedChildBox' => ['x' => 160.0, 'y' => 35.0, 'width' => 50.0, 'height' => 20.0],
        ];

        yield 'row layout falls back to container height when item cross size is zero' => [
            'itemSize' => ['width' => 50.0, 'height' => 0.0],
            'direction' => 'row',
            'alignItems' => 'center',
            'contentBox' => ['x' => 10.0, 'y' => 20.0, 'width' => 200.0, 'height' => 100.0],
            'crossContainerSize' => 100.0,
            'cursor' => 30.0,
            'expectedChildBox' => ['height' => 100.0],
        ];

        yield 'column layout falls back to container width when item cross size is zero' => [
            'itemSize' => ['width' => 0.0, 'height' => 20.0],
            'direction' => 'column',
            'alignItems' => 'flex-end',
            'contentBox' => ['x' => 10.0, 'y' => 20.0, 'width' => 200.0, 'height' => 100.0],
            'crossContainerSize' => 200.0,
            'cursor' => 15.0,
            'expectedChildBox' => ['width' => 200.0],
        ];

        yield 'row layout clamps oversized center alignment to container top' => [
            'itemSize' => ['width' => 50.0, 'height' => 150.0],
            'direction' => 'row',
            'alignItems' => 'center',
            'contentBox' => ['x' => 10.0, 'y' => 20.0, 'width' => 200.0, 'height' => 100.0],
            'crossContainerSize' => 100.0,
            'cursor' => 30.0,
            'expectedChildBox' => ['y' => 20.0],
        ];

        yield 'column layout clamps oversized flex-end alignment to container left' => [
            'itemSize' => ['width' => 250.0, 'height' => 20.0],
            'direction' => 'column',
            'alignItems' => 'flex-end',
            'contentBox' => ['x' => 10.0, 'y' => 20.0, 'width' => 200.0, 'height' => 100.0],
            'crossContainerSize' => 200.0,
            'cursor' => 15.0,
            'expectedChildBox' => ['x' => 10.0],
        ];

        yield 'column layout positions centered child within cross axis' => [
            'itemSize' => ['width' => 50.0, 'height' => 20.0],
            'direction' => 'column',
            'alignItems' => 'center',
            'contentBox' => ['x' => 10.0, 'y' => 20.0, 'width' => 200.0, 'height' => 100.0],
            'crossContainerSize' => 200.0,
            'cursor' => 15.0,
            'expectedChildBox' => ['x' => 85.0, 'y' => 35.0, 'width' => 50.0, 'height' => 20.0],
        ];
    }

    /**
     * @return iterable<string, array{
     *     itemSize: array{width: float, height: float},
     *     direction: string,
     *     gap: float,
     *     expectedAdvance: float
     * }>
     */
    public static function advanceCursorProvider(): iterable
    {
        yield 'row cursor advances by width plus gap' => [
            'itemSize' => ['width' => 50.0, 'height' => 20.0],
            'direction' => 'row',
            'gap' => 7.0,
            'expectedAdvance' => 57.0,
        ];

        yield 'column cursor advances by height plus gap' => [
            'itemSize' => ['width' => 50.0, 'height' => 20.0],
            'direction' => 'column',
            'gap' => 7.0,
            'expectedAdvance' => 27.0,
        ];
    }

    #[DataProvider('normalizedDirectionProvider')]
    public function testNormalizeDirectionReturnsExpectedValue(
        string $direction,
        string $expectedDirection,
    ): void {
        $planner = new StructuredFlexLayoutPlanner(new LayoutStyleResolver());

        self::assertSame($expectedDirection, $planner->normalizeDirection($direction));
    }

    #[DataProvider('resolveGapProvider')]
    public function testResolveGapUsesExpectedAxis(
        string $inlineStyle,
        string $direction,
        array $contentBox,
        float $expectedGap,
    ): void {
        $planner = new StructuredFlexLayoutPlanner(new LayoutStyleResolver());
        $style = (new InlineStyleParser())->parse($inlineStyle);

        self::assertSame($expectedGap, $planner->resolveGap($style, $direction, $contentBox));
    }

    #[DataProvider('measureItemProvider')]
    public function testMeasureItemUsesExpectedFallbacks(
        Node $node,
        string $inlineStyle,
        array $container,
        array $expectedSize,
    ): void {
        $planner = new StructuredFlexLayoutPlanner(new LayoutStyleResolver());
        $style = (new InlineStyleParser())->parse($inlineStyle);

        self::assertSame($expectedSize, $planner->measureItem($node, $style, $container));
    }

    #[DataProvider('calculateMetricsProvider')]
    public function testCalculateMetricsReturnsExpectedValues(
        array $itemSizes,
        string $direction,
        string $justifyContent,
        float $gap,
        array $contentBox,
        array $expectedMetrics,
    ): void {
        $planner = new StructuredFlexLayoutPlanner(new LayoutStyleResolver());
        $items = array_map(
            fn (array $itemSize): array => $this->createFlexItem($itemSize['width'], $itemSize['height']),
            $itemSizes,
        );

        $metrics = $planner->calculateMetrics(
            $items,
            $direction,
            $justifyContent,
            $gap,
            $contentBox,
        );

        foreach ($expectedMetrics as $metric => $expectedValue) {
            self::assertSame($expectedValue, $metrics[$metric]);
        }
    }

    #[DataProvider('createChildBoxProvider')]
    public function testCreateChildBoxReturnsExpectedGeometry(
        array $itemSize,
        string $direction,
        string $alignItems,
        array $contentBox,
        float $crossContainerSize,
        float $cursor,
        array $expectedChildBox,
    ): void {
        $planner = new StructuredFlexLayoutPlanner(new LayoutStyleResolver());
        $item = $this->createFlexItem($itemSize['width'], $itemSize['height']);

        $childBox = $planner->createChildBox(
            $item,
            $direction,
            $alignItems,
            $contentBox,
            $crossContainerSize,
            $cursor,
        );

        foreach ($expectedChildBox as $property => $expectedValue) {
            self::assertSame($expectedValue, $childBox[$property]);
        }
    }

    #[DataProvider('advanceCursorProvider')]
    public function testAdvanceCursorUsesMainAxisDimension(
        array $itemSize,
        string $direction,
        float $gap,
        float $expectedAdvance,
    ): void {
        $planner = new StructuredFlexLayoutPlanner(new LayoutStyleResolver());
        $item = $this->createFlexItem($itemSize['width'], $itemSize['height']);

        self::assertSame($expectedAdvance, $planner->advanceCursor($item, $direction, $gap));
    }

    /**
     * @return array{
     *     node: Node,
     *     style: \LibreSign\XObjectTemplate\Css\StyleMap,
     *     size: array{width: float, height: float}
     * }
     */
    private function createFlexItem(float $width, float $height): array
    {
        return [
            'node' => new Node(tag: 'div', text: '', attributes: []),
            'style' => (new InlineStyleParser())->parse(''),
            'size' => ['width' => $width, 'height' => $height],
        ];
    }
}
