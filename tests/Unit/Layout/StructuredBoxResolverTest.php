<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Layout;

use LibreSign\XObjectTemplate\Css\InlineStyleParser;
use LibreSign\XObjectTemplate\Html\Node;
use LibreSign\XObjectTemplate\Layout\LayoutStyleResolver;
use LibreSign\XObjectTemplate\Layout\StructuredBoxResolver;
use PHPUnit\Framework\TestCase;

final class StructuredBoxResolverTest extends TestCase
{
    public function testResolveFlowPlacementSupportsPercentageDimensionsForImages(): void
    {
        $parser = new InlineStyleParser();
        $resolver = new StructuredBoxResolver(new LayoutStyleResolver());
        $style = $parser->parse('width:50%;height:40%;margin:2 4 6 8');

        $placement = $resolver->resolveFlowPlacement(
            new Node(tag: 'img', text: '', attributes: ['src' => '/preview.png']),
            $style,
            ['x' => 0.0, 'y' => 0.0, 'width' => 200.0, 'height' => 100.0],
        );

        self::assertSame(['top' => 2.0, 'right' => 4.0, 'bottom' => 6.0, 'left' => 8.0], $placement['margin']);
        self::assertSame(8.0, $placement['box']['x']);
        self::assertSame(2.0, $placement['box']['y']);
        self::assertSame(100.0, $placement['box']['width']);
        self::assertSame(40.0, $placement['box']['height']);
    }

    public function testResolveAbsoluteBoxSupportsRightAndBottomOffsets(): void
    {
        $parser = new InlineStyleParser();
        $resolver = new StructuredBoxResolver(new LayoutStyleResolver());
        $style = $parser->parse('position:absolute;width:60;height:20;right:10;bottom:15;margin:1 2 3 4');

        $box = $resolver->resolveAbsoluteBox(
            new Node(tag: 'div', text: '', attributes: []),
            $style,
            ['x' => 0.0, 'y' => 0.0, 'width' => 200.0, 'height' => 100.0],
        );

        self::assertSame(128.0, $box['x']);
        self::assertSame(62.0, $box['y']);
        self::assertSame(60.0, $box['width']);
        self::assertSame(20.0, $box['height']);
    }

    public function testResolveContentBoxAndChildContainerSubtractPaddingAndConsumedHeight(): void
    {
        $parser = new InlineStyleParser();
        $resolver = new StructuredBoxResolver(new LayoutStyleResolver());
        $style = $parser->parse('padding:10 20 30 40');

        $resolved = $resolver->resolveContentBox(
            $style,
            ['x' => 5.0, 'y' => 6.0, 'width' => 200.0, 'height' => 100.0],
        );
        $childContainer = $resolver->createChildContainer($resolved['contentBox'], 12.0);

        self::assertSame(['top' => 10.0, 'right' => 20.0, 'bottom' => 30.0, 'left' => 40.0], $resolved['padding']);
        self::assertSame(45.0, $resolved['contentBox']['x']);
        self::assertSame(16.0, $resolved['contentBox']['y']);
        self::assertSame(140.0, $resolved['contentBox']['width']);
        self::assertSame(60.0, $resolved['contentBox']['height']);
        self::assertSame(28.0, $childContainer['y']);
        self::assertSame(48.0, $childContainer['height']);
    }

    public function testResolveAutoContainerHeightUsesResolvedHeightWhenPresent(): void
    {
        $resolver = new StructuredBoxResolver(new LayoutStyleResolver());

        self::assertSame(80.0, $resolver->resolveAutoContainerHeight(
            80.0,
            ['top' => 5.0, 'right' => 0.0, 'bottom' => 5.0, 'left' => 0.0],
            40.0,
        ));
        self::assertSame(50.0, $resolver->resolveAutoContainerHeight(
            0.0,
            ['top' => 5.0, 'right' => 0.0, 'bottom' => 5.0, 'left' => 0.0],
            40.0,
        ));
    }
}
