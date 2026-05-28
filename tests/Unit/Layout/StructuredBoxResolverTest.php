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

    public function testResolveContentBoxUsesWidthAsVerticalReferenceWhenHeightIsNotPositive(): void
    {
        $parser = new InlineStyleParser();
        $resolver = new StructuredBoxResolver(new LayoutStyleResolver());
        $style = $parser->parse('padding:10%');

        $resolved = $resolver->resolveContentBox(
            $style,
            ['x' => 0.0, 'y' => 0.0, 'width' => 200.0, 'height' => 0.0],
        );

        self::assertSame(['top' => 20.0, 'right' => 20.0, 'bottom' => 20.0, 'left' => 20.0], $resolved['padding']);
        self::assertSame(['x' => 20.0, 'y' => 20.0, 'width' => 160.0, 'height' => 0.0], $resolved['contentBox']);
    }

    public function testResolveContentBoxAndChildContainerClampCollapsedDimensionsToZero(): void
    {
        $parser = new InlineStyleParser();
        $resolver = new StructuredBoxResolver(new LayoutStyleResolver());
        $style = $parser->parse('padding:10 15');

        $resolved = $resolver->resolveContentBox(
            $style,
            ['x' => 3.0, 'y' => 4.0, 'width' => 20.0, 'height' => 15.0],
        );
        $childContainer = $resolver->createChildContainer($resolved['contentBox'], 7.0);

        self::assertSame(0.0, $resolved['contentBox']['width']);
        self::assertSame(0.0, $resolved['contentBox']['height']);
        self::assertSame(0.0, $childContainer['height']);
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

    public function testResolveFixedContainerHeightKeepsExplicitHeightFixed(): void
    {
        $resolver = new StructuredBoxResolver(new LayoutStyleResolver());

        self::assertSame(30.0, $resolver->resolveFixedContainerHeight(
            30.0,
            ['top' => 5.0, 'right' => 0.0, 'bottom' => 5.0, 'left' => 0.0],
            40.0,
        ));

        self::assertSame(52.0, $resolver->resolveFixedContainerHeight(
            0.0,
            ['top' => 5.0, 'right' => 0.0, 'bottom' => 7.0, 'left' => 0.0],
            40.0,
        ));
    }

    public function testResolveFlowPlacementUsesDefaultBlockAndImageFallbackDimensions(): void
    {
        $parser = new InlineStyleParser();
        $resolver = new StructuredBoxResolver(new LayoutStyleResolver());
        $style = $parser->parse('margin:3 4 5 6');

        $blockPlacement = $resolver->resolveFlowPlacement(
            new Node(tag: 'div', text: '', attributes: []),
            $style,
            ['x' => 0.0, 'y' => 0.0, 'width' => 120.0, 'height' => 80.0],
        );
        $imagePlacement = $resolver->resolveFlowPlacement(
            new Node(tag: 'img', text: '', attributes: ['src' => '/icon.png']),
            $style,
            ['x' => 0.0, 'y' => 0.0, 'width' => 120.0, 'height' => 80.0],
        );

        self::assertSame(['x' => 6.0, 'y' => 3.0, 'width' => 110.0, 'height' => 0.0], $blockPlacement['box']);
        self::assertSame(['x' => 6.0, 'y' => 3.0, 'width' => 32.0, 'height' => 32.0], $imagePlacement['box']);
    }

    public function testResolveAbsoluteBoxSupportsDefaultMarginsAndLeftTopOffsets(): void
    {
        $parser = new InlineStyleParser();
        $resolver = new StructuredBoxResolver(new LayoutStyleResolver());

        $marginOnlyBox = $resolver->resolveAbsoluteBox(
            new Node(tag: 'div', text: '', attributes: []),
            $parser->parse('position:absolute;margin:3 4 5 6'),
            ['x' => 0.0, 'y' => 0.0, 'width' => 100.0, 'height' => 80.0],
        );
        $offsetBox = $resolver->resolveAbsoluteBox(
            new Node(tag: 'div', text: '', attributes: []),
            $parser->parse('position:absolute;width:20;height:10;left:7;top:9;margin:1 2 3 4'),
            ['x' => 0.0, 'y' => 0.0, 'width' => 100.0, 'height' => 80.0],
        );

        self::assertSame(['x' => 6.0, 'y' => 3.0, 'width' => 90.0, 'height' => 72.0], $marginOnlyBox);
        self::assertSame(['x' => 11.0, 'y' => 10.0, 'width' => 20.0, 'height' => 10.0], $offsetBox);
    }

    public function testResolveFlowAndAbsoluteFallbackDimensionsClampToZeroInsteadOfOne(): void
    {
        $parser = new InlineStyleParser();
        $resolver = new StructuredBoxResolver(new LayoutStyleResolver());

        $flowPlacement = $resolver->resolveFlowPlacement(
            new Node(tag: 'div', text: '', attributes: []),
            $parser->parse('margin:0 5'),
            ['x' => 0.0, 'y' => 0.0, 'width' => 10.0, 'height' => 10.0],
        );
        $absolutePlacement = $resolver->resolveAbsoluteBox(
            new Node(tag: 'div', text: '', attributes: []),
            $parser->parse('position:absolute;margin:5'),
            ['x' => 0.0, 'y' => 0.0, 'width' => 10.0, 'height' => 10.0],
        );
        $absoluteImagePlacement = $resolver->resolveAbsoluteBox(
            new Node(tag: 'img', text: '', attributes: ['src' => '/icon.png']),
            $parser->parse('position:absolute'),
            ['x' => 0.0, 'y' => 0.0, 'width' => 100.0, 'height' => 80.0],
        );

        self::assertSame(0.0, $flowPlacement['box']['width']);
        self::assertSame(0.0, $absolutePlacement['width']);
        self::assertSame(0.0, $absolutePlacement['height']);
        self::assertSame(32.0, $absoluteImagePlacement['width']);
        self::assertSame(32.0, $absoluteImagePlacement['height']);
    }

    public function testResolveAbsoluteRightAndBottomClampToZeroInsteadOfOne(): void
    {
        $parser = new InlineStyleParser();
        $resolver = new StructuredBoxResolver(new LayoutStyleResolver());

        $box = $resolver->resolveAbsoluteBox(
            new Node(tag: 'div', text: '', attributes: []),
            $parser->parse('position:absolute;width:30;height:20;right:90;bottom:70'),
            ['x' => 0.0, 'y' => 0.0, 'width' => 100.0, 'height' => 80.0],
        );

        self::assertSame(0.0, $box['x']);
        self::assertSame(0.0, $box['y']);
    }
}
