<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf;

use InvalidArgumentException;
use LibreSign\XObjectTemplate\Pdf\Svg\SvgPdfXObjectFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SvgPdfXObjectFactoryTest extends TestCase
{
    public function testCreateBuildsFormXObjectFromPathBasedSvg(): void
    {
        $factory = new SvgPdfXObjectFactory();

        $xObject = $factory->create(
            <<<'SVG'
<?xml version="1.0" encoding="UTF-8"?>
<svg width="10" height="8" viewBox="0 0 10 8" xmlns="http://www.w3.org/2000/svg">
  <style>
    .accent { fill: #112233; }
  </style>
  <path class="accent" d="M0,0 L10,0 L10,8 L0,8 z"/>
</svg>
SVG,
            '/tmp/test.svg',
        );

        self::assertSame('/XObject', $xObject->dictionary['Type']);
        self::assertSame('/Form', $xObject->dictionary['Subtype']);
        self::assertSame(1, $xObject->dictionary['FormType']);
        self::assertSame([0.0, 0.0, 10.0, 8.0], $xObject->dictionary['BBox']);
        self::assertStringContainsString('0.0667 0.1333 0.2 rg', $xObject->stream);
        self::assertStringContainsString('0.000000 8.000000 m', $xObject->stream);
        self::assertStringContainsString('10.000000 0.000000 l', $xObject->stream);
        self::assertStringContainsString('h', $xObject->stream);
        self::assertStringContainsString('f', $xObject->stream);
    }

    public function testCreateSupportsPolygonAndRectElements(): void
    {
        $factory = new SvgPdfXObjectFactory();

        $xObject = $factory->create(
            <<<'SVG'
<svg width="20" height="20" xmlns="http://www.w3.org/2000/svg">
  <polygon fill="#ff0000" points="0,0 10,0 10,10 0,10"/>
  <rect x="10" y="10" width="10" height="10" style="fill:#00ff00"/>
</svg>
SVG,
            '/tmp/polygon-rect.svg',
        );

        self::assertSame([0.0, 0.0, 20.0, 20.0], $xObject->dictionary['BBox']);
        self::assertStringContainsString('1 0 0 rg', $xObject->stream);
        self::assertStringContainsString('0.000000 20.000000 m', $xObject->stream);
        self::assertStringContainsString('0 1 0 rg', $xObject->stream);
        self::assertStringContainsString('10.000000 10.000000 m', $xObject->stream);
        self::assertStringContainsString('20.000000 0.000000 l', $xObject->stream);
    }

    public function testCreateRejectsInvalidSvgPayloads(): void
    {
        $factory = new SvgPdfXObjectFactory();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to parse SVG source "/tmp/invalid.svg".');

        $factory->create('<html></html>', '/tmp/invalid.svg');
    }

    #[DataProvider('provideInvalidViewportScenarios')]
    public function testCreateRejectsInvalidViewportScenarios(string $svg, string $expectedMessage): void
    {
        $factory = new SvgPdfXObjectFactory();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        $factory->create($svg, '/tmp/invalid-viewport.svg');
    }

    public function testCreateSupportsTransformOperationsAndStrokeInheritance(): void
    {
        $factory = new SvgPdfXObjectFactory();

        $xObject = $factory->create(
            <<<'SVG'
<svg width="40" height="20" viewBox="0 0 40 20" xmlns="http://www.w3.org/2000/svg">
  <style>.shape{fill:rgb(10,20,30);stroke:#ff0000;}</style>
  <g transform="translate(2,3) rotate(10 5 5) scale(1.1,0.9) skewX(3) skewY(2)">
    <path class="shape" d="M 1,1 L 8,1 H 10 V 5 Z"/>
  </g>
  <g stroke="#00ff00" transform="matrix(1,0,0,1,3,0)">
    <line x1="0" y1="10" x2="10" y2="10" fill="none"/>
  </g>
</svg>
SVG,
            '/tmp/transforms.svg',
        );

        self::assertSame([0.0, 0.0, 40.0, 20.0], $xObject->dictionary['BBox']);
        self::assertStringContainsString('0.0392 0.0784 0.1176 rg', $xObject->stream);
        self::assertStringContainsString('1 0 0 RG', $xObject->stream);
        self::assertStringContainsString('0 1 0 RG', $xObject->stream);
        self::assertStringContainsString('B', $xObject->stream);
        self::assertStringContainsString('S', $xObject->stream);
    }

    public function testCreateSkipsInvalidOrUnpaintedShapesWithoutFailing(): void
    {
        $factory = new SvgPdfXObjectFactory();

        $xObject = $factory->create(
            <<<'SVG'
<svg width="20" height="20" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
  <polygon fill="#ff0000" points="0,0 10,0 10"/>
  <polyline stroke="#ff0000" points="0,0 10"/>
  <rect x="1" y="1" width="0" height="10" fill="#000"/>
  <circle cx="8" cy="8" r="0" fill="#000"/>
  <ellipse cx="12" cy="12" rx="6" ry="0" fill="#000"/>
  <path d="M 1,1 L 5,5" fill="none" stroke="none"/>
  <path d="M 2,2 L 9,2" fill="#123456"/>
</svg>
SVG,
            '/tmp/skip-invalid-shapes.svg',
        );

        self::assertSame([0.0, 0.0, 20.0, 20.0], $xObject->dictionary['BBox']);
        self::assertStringContainsString('0.0706 0.2039 0.3373 rg', $xObject->stream);
        self::assertStringContainsString('f', $xObject->stream);
        self::assertStringNotContainsString('RG', $xObject->stream);
    }

    public function testCreateRejectsMalformedAndUnsupportedPathCommands(): void
    {
        $factory = new SvgPdfXObjectFactory();

        try {
            $factory->create(
                '<svg width="10" height="10" xmlns="http://www.w3.org/2000/svg"><path fill="#000" d="M 1"/></svg>',
                '/tmp/malformed-path.svg',
            );
            self::fail('Expected malformed path to be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('Malformed SVG path data', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SVG path command "R" is not supported');

        $factory->create(
            '<svg width="10" height="10" xmlns="http://www.w3.org/2000/svg"><path fill="#000" d="M 1,1 R 2,2"/></svg>',
            '/tmp/unsupported-path.svg',
        );
    }

    #[DataProvider('provideSupportedShapeScenarios')]
    public function testCreateSupportsShapeScenarios(
        string $svg,
        string $sourcePath,
        array $expectedBBox,
        array $requiredStreamFragments,
        ?int $minimumCubicBezierOperators = null,
    ): void {
        $factory = new SvgPdfXObjectFactory();
        $xObject = $factory->create($svg, $sourcePath);

        self::assertSame($expectedBBox, $xObject->dictionary['BBox']);

        foreach ($requiredStreamFragments as $requiredStreamFragment) {
            self::assertStringContainsString($requiredStreamFragment, $xObject->stream);
        }

        if ($minimumCubicBezierOperators !== null) {
            self::assertGreaterThanOrEqual($minimumCubicBezierOperators, substr_count($xObject->stream, ' c'));
        }
    }

    #[DataProvider('providePaintModeScenarios')]
    public function testCreateRendersPaintModeScenarios(
        string $svg,
        string $sourcePath,
        array $expectedBBox,
        array $requiredStreamFragments,
        array $forbiddenStreamFragments,
    ): void {
        $factory = new SvgPdfXObjectFactory();

        $xObject = $factory->create($svg, $sourcePath);

        self::assertSame($expectedBBox, $xObject->dictionary['BBox']);

        foreach ($requiredStreamFragments as $requiredStreamFragment) {
            self::assertStringContainsString($requiredStreamFragment, $xObject->stream);
        }

        foreach ($forbiddenStreamFragments as $forbiddenStreamFragment) {
            self::assertStringNotContainsString($forbiddenStreamFragment, $xObject->stream);
        }
    }

    public static function provideSupportedShapeScenarios(): iterable
    {
        yield 'quadratic bezier path commands' => [
            <<<'SVG'
<svg width="20" height="10" viewBox="0 0 20 10" xmlns="http://www.w3.org/2000/svg">
  <path fill="#0000ff" d="M 0,10 Q 5,0 10,10 T 20,10"/>
</svg>
SVG,
            '/tmp/quadratic.svg',
            [0.0, 0.0, 20.0, 10.0],
            [' c', '0 0 1 rg'],
            null,
        ];

        yield 'absolute arc command' => [
            <<<'SVG'
<svg width="20" height="10" viewBox="0 0 20 10" xmlns="http://www.w3.org/2000/svg">
  <path fill="#ff0000" d="M 0,5 A 10,5 0 0 1 20,5 Z"/>
</svg>
SVG,
            '/tmp/arc.svg',
            [0.0, 0.0, 20.0, 10.0],
            [' c', '1 0 0 rg'],
            null,
        ];

        yield 'relative arc command' => [
            <<<'SVG'
<svg width="20" height="10" viewBox="0 0 20 10" xmlns="http://www.w3.org/2000/svg">
  <path fill="#00ff00" d="M 0,5 a 10,5 0 0 1 20,0 Z"/>
</svg>
SVG,
            '/tmp/arc-relative.svg',
            [0.0, 0.0, 20.0, 10.0],
            [' c'],
            null,
        ];

        yield 'circle element' => [
            <<<'SVG'
<svg width="20" height="20" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
  <circle cx="10" cy="10" r="8" fill="#ff8800"/>
</svg>
SVG,
            '/tmp/circle.svg',
            [0.0, 0.0, 20.0, 20.0],
            [],
            4,
        ];

        yield 'ellipse element' => [
            <<<'SVG'
<svg width="30" height="20" viewBox="0 0 30 20" xmlns="http://www.w3.org/2000/svg">
  <ellipse cx="15" cy="10" rx="14" ry="8" fill="#0088ff"/>
</svg>
SVG,
            '/tmp/ellipse.svg',
            [0.0, 0.0, 30.0, 20.0],
            [],
            4,
        ];

        yield 'fill inherited from parent group' => [
            <<<'SVG'
<svg width="10" height="10" viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg">
  <g fill="#ff0000">
    <path d="M 0,0 L 10,0 L 10,10 L 0,10 Z"/>
  </g>
</svg>
SVG,
            '/tmp/group-fill.svg',
            [0.0, 0.0, 10.0, 10.0],
            ['1 0 0 rg'],
            null,
        ];

        yield 'group translate transform affects child geometry' => [
            <<<'SVG'
<svg width="20" height="10" viewBox="0 0 20 10" xmlns="http://www.w3.org/2000/svg">
  <g transform="translate(5,0)">
    <rect x="1" y="7" width="3" height="2" fill="#000000"/>
  </g>
</svg>
SVG,
            '/tmp/group-transform-translate.svg',
            [0.0, 0.0, 20.0, 10.0],
            ['6.000000 3.000000 m', '9.000000 1.000000 l'],
            null,
        ];
    }

    public static function provideInvalidViewportScenarios(): iterable
    {
        yield 'invalid viewbox token count' => [
            '<svg viewBox="0 0 10" xmlns="http://www.w3.org/2000/svg"><path d="M0,0"/></svg>',
            'Invalid viewBox in SVG source "/tmp/invalid-viewport.svg".',
        ];

        yield 'non-positive viewbox dimensions' => [
            '<svg viewBox="0 0 0 10" xmlns="http://www.w3.org/2000/svg"><path d="M0,0"/></svg>',
            'SVG source "/tmp/invalid-viewport.svg" must define a positive viewBox.',
        ];

        yield 'missing usable viewport and dimensions' => [
            '<svg width="auto" height="" xmlns="http://www.w3.org/2000/svg"><path d="M0,0"/></svg>',
            'SVG source "/tmp/invalid-viewport.svg" must define either a valid viewBox or positive width/height.',
        ];
    }

    public static function providePaintModeScenarios(): iterable
    {
        yield 'stroke only path without fill' => [
            <<<'SVG'
<svg width="10" height="10" viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg">
  <path style="fill:none;stroke:#0000ff;stroke-width:2" d="M 0,5 L 10,5"/>
</svg>
SVG,
            '/tmp/stroke-only.svg',
            [0.0, 0.0, 10.0, 10.0],
            ['0 0 1 RG', '2.000000 w', "\nS\n"],
            ["\nf\n"],
        ];

        yield 'fill and stroke together' => [
            <<<'SVG'
<svg width="10" height="10" viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg">
  <rect x="1" y="1" width="8" height="8" fill="#ff0000" stroke="#000000" stroke-width="1"/>
</svg>
SVG,
            '/tmp/fill-stroke.svg',
            [0.0, 0.0, 10.0, 10.0],
            ['1 0 0 rg', '0 0 0 RG', "\nB\n"],
            [],
        ];
    }
}
