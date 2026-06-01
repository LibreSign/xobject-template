<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf\Svg;

use DOMDocument;
use DOMElement;
use LibreSign\XObjectTemplate\Pdf\Svg\SvgElementPathBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SvgElementPathBuilderTest extends TestCase
{
    /**
     * @return array{0:float,1:float,2:float,3:float,4:float,5:float}
     */
    private static function identityTransform(): array
    {
        return [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
    }

    private static function createElement(string $markup): DOMElement
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadXML('<svg xmlns="http://www.w3.org/2000/svg">' . $markup . '</svg>');
        self::assertTrue($loaded);

        $element = $document->documentElement?->firstElementChild;
        self::assertInstanceOf(DOMElement::class, $element);

        return $element;
    }

    #[DataProvider('provideInvalidPathScenarios')]
    public function testBuildElementPathReturnsNullForInvalidScenarios(
        string $markup,
        float $minX,
        float $maxY,
    ): void {
        $builder = new SvgElementPathBuilder();
        $element = self::createElement($markup);

        $result = $builder->buildElementPath($element, $minX, $maxY, '/tmp/source.svg', self::identityTransform());

        self::assertNull($result);
    }

    #[DataProvider('provideExpectedPathScenarios')]
    public function testBuildElementPathBuildsExpectedPath(
        string $markup,
        float $minX,
        float $maxY,
        string $expected,
    ): void {
        $builder = new SvgElementPathBuilder();
        $element = self::createElement($markup);

        $result = $builder->buildElementPath($element, $minX, $maxY, '/tmp/source.svg', self::identityTransform());

        self::assertSame($expected, $result);
    }

    public function testBuildElementPathBuildsCircleWithFourCubicSegmentsAndClosedPath(): void
    {
        $builder = new SvgElementPathBuilder();
        $element = self::createElement('<circle cx="5" cy="5" r="2" />');

        $result = $builder->buildElementPath($element, 2.0, 10.0, '/tmp/source.svg', self::identityTransform());

        self::assertSame(
            "3.000000 7.000000 m\n"
            . "4.104569 7.000000 5.000000 6.104569 5.000000 5.000000 c\n"
            . "5.000000 3.895431 4.104569 3.000000 3.000000 3.000000 c\n"
            . "1.895431 3.000000 1.000000 3.895431 1.000000 5.000000 c\n"
            . "1.000000 6.104569 1.895431 7.000000 3.000000 7.000000 c\n"
            . "h",
            $result,
        );
    }

    public function testBuildElementPathBuildsEllipsePathWithExpectedAnchorPoints(): void
    {
        $builder = new SvgElementPathBuilder();
        $element = self::createElement('<ellipse cx="10" cy="10" rx="4" ry="2" />');

        $result = $builder->buildElementPath($element, 2.0, 20.0, '/tmp/source.svg', self::identityTransform());

        self::assertSame(
            "8.000000 12.000000 m\n"
            . "10.209139 12.000000 12.000000 11.104569 12.000000 10.000000 c\n"
            . "12.000000 8.895431 10.209139 8.000000 8.000000 8.000000 c\n"
            . "5.790861 8.000000 4.000000 8.895431 4.000000 10.000000 c\n"
            . "4.000000 11.104569 5.790861 12.000000 8.000000 12.000000 c\n"
            . "h",
            $result,
        );
    }

    /**
     * @return iterable<string, array{markup: string, minX: float, maxY: float}>
     */
    public static function provideInvalidPathScenarios(): iterable
    {
        yield 'unsupported element' => [
            'markup' => '<text x="1" y="2">noop</text>',
            'minX' => 0.0,
            'maxY' => 10.0,
        ];

        yield 'path with only whitespace' => [
            'markup' => '<path d="   " />',
            'minX' => 0.0,
            'maxY' => 10.0,
        ];

        yield 'polygon with only whitespace points' => [
            'markup' => '<polygon points="   " />',
            'minX' => 0.0,
            'maxY' => 10.0,
        ];

        yield 'polygon with odd coordinate count' => [
            'markup' => '<polygon points="1,1 3,1 3" />',
            'minX' => 0.0,
            'maxY' => 10.0,
        ];

        yield 'polyline with only whitespace points' => [
            'markup' => '<polyline points="   " />',
            'minX' => 0.0,
            'maxY' => 10.0,
        ];

        yield 'polyline with odd coordinate count' => [
            'markup' => '<polyline points="1,1 2" />',
            'minX' => 0.0,
            'maxY' => 10.0,
        ];

        yield 'polyline with five numeric tokens' => [
            'markup' => '<polyline points="1,1 2,2 3" />',
            'minX' => 0.0,
            'maxY' => 10.0,
        ];

        yield 'rect with zero width' => [
            'markup' => '<rect x="1" y="2" width="0" height="3" />',
            'minX' => 0.0,
            'maxY' => 10.0,
        ];

        yield 'rect with zero height' => [
            'markup' => '<rect x="1" y="2" width="3" height="0" />',
            'minX' => 0.0,
            'maxY' => 10.0,
        ];

        yield 'circle with zero radius' => [
            'markup' => '<circle cx="5" cy="5" r="0" />',
            'minX' => 0.0,
            'maxY' => 10.0,
        ];

        yield 'ellipse with zero rx' => [
            'markup' => '<ellipse cx="5" cy="5" rx="0" ry="2" />',
            'minX' => 0.0,
            'maxY' => 10.0,
        ];

        yield 'ellipse with zero ry' => [
            'markup' => '<ellipse cx="5" cy="5" rx="2" ry="0" />',
            'minX' => 0.0,
            'maxY' => 10.0,
        ];

        yield 'non numeric prefix in length' => [
            'markup' => '<rect x="0" y="0" width="abc3" height="2" />',
            'minX' => 0.0,
            'maxY' => 10.0,
        ];

        yield 'missing width attribute is treated as empty length' => [
            'markup' => '<rect x="0" y="0" height="2" />',
            'minX' => 0.0,
            'maxY' => 10.0,
        ];
    }

    /**
     * @return iterable<string, array{markup: string, minX: float, maxY: float, expected: string}>
     */
    public static function provideExpectedPathScenarios(): iterable
    {
        yield 'path command with minX offset' => [
            'markup' => '<path d="M 1,2 L 3,4" />',
            'minX' => 2.0,
            'maxY' => 10.0,
            'expected' => "-1.000000 8.000000 m\n1.000000 6.000000 l",
        ];

        yield 'polygon with spaces' => [
            'markup' => '<polygon points=" 1,1 3,1 3,3 " />',
            'minX' => 2.0,
            'maxY' => 10.0,
            'expected' => "-1.000000 9.000000 m\n1.000000 9.000000 l\n1.000000 7.000000 l\nh",
        ];

        yield 'polygon first pair used as move-to' => [
            'markup' => '<polygon points="2,7 4,1 6,2" />',
            'minX' => 1.0,
            'maxY' => 12.0,
            'expected' => "1.000000 5.000000 m\n3.000000 11.000000 l\n5.000000 10.000000 l\nh",
        ];

        yield 'polygon with exactly four numbers' => [
            'markup' => '<polygon points="1,1 3,3" />',
            'minX' => 2.0,
            'maxY' => 10.0,
            'expected' => "-1.000000 9.000000 m\n1.000000 7.000000 l\nh",
        ];

        yield 'polyline with two points' => [
            'markup' => '<polyline points="2,7 8,3" />',
            'minX' => 1.0,
            'maxY' => 12.0,
            'expected' => "1.000000 5.000000 m\n7.000000 9.000000 l",
        ];

        yield 'polyline with three points' => [
            'markup' => '<polyline points="1,1 4,2 6,5" />',
            'minX' => 2.0,
            'maxY' => 10.0,
            'expected' => "-1.000000 9.000000 m\n2.000000 8.000000 l\n4.000000 5.000000 l",
        ];

        yield 'polyline preserves distinct first x and y' => [
            'markup' => '<polyline points="2,7 4,1 6,2" />',
            'minX' => 1.0,
            'maxY' => 12.0,
            'expected' => "1.000000 5.000000 m\n3.000000 11.000000 l\n5.000000 10.000000 l",
        ];

        yield 'rect clockwise path' => [
            'markup' => '<rect x="1" y="2" width="3" height="4" />',
            'minX' => 2.0,
            'maxY' => 10.0,
            'expected' => "-1.000000 8.000000 m\n2.000000 8.000000 l\n2.000000 4.000000 l\n-1.000000 4.000000 l\nh",
        ];

        yield 'line basic lengths' => [
            'markup' => '<line x1="1" y1="2" x2="3" y2="4" />',
            'minX' => 2.0,
            'maxY' => 10.0,
            'expected' => "-1.000000 8.000000 m\n1.000000 6.000000 l",
        ];

        yield 'line with whitespace lengths' => [
            'markup' => '<line x1=" 1 " y1=" 2 " x2=" 3 " y2=" 4 " />',
            'minX' => 2.0,
            'maxY' => 10.0,
            'expected' => "-1.000000 8.000000 m\n1.000000 6.000000 l",
        ];
    }
}
