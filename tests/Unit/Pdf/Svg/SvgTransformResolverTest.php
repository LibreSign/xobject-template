<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf\Svg;

use DOMDocument;
use DOMElement;
use LibreSign\XObjectTemplate\Pdf\Svg\SvgTransformResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SvgTransformResolverTest extends TestCase
{
    public function testApplyTransformToPointUsesAffineMatrixCoordinates(): void
    {
        $resolver = new SvgTransformResolver();

        self::assertSame([16.0, 20.0], $resolver->applyTransformToPoint([2.0, 3.0, 4.0, 5.0, 6.0, 7.0], 1.0, 2.0));
    }

    #[DataProvider('provideSingleTransformScenarios')]
    public function testResolveElementTransformMatrixSupportsIndividualTransformOperators(
        string $transform,
        float $x,
        float $y,
        array $expectedPoint,
    ): void {
        $resolver = new SvgTransformResolver();
        $element = $this->createNestedElement([$transform]);

        $matrix = $resolver->resolveElementTransformMatrix($element);
        [$actualX, $actualY] = $resolver->applyTransformToPoint($matrix, $x, $y);

        self::assertEqualsWithDelta($expectedPoint[0], $actualX, 0.0001);
        self::assertEqualsWithDelta($expectedPoint[1], $actualY, 0.0001);
    }

    public function testResolveElementTransformMatrixCombinesAncestorTransformsFromRootToLeaf(): void
    {
        $resolver = new SvgTransformResolver();
        $element = $this->createNestedElement(['translate(2,3)', 'scale(2,2)']);

        $matrix = $resolver->resolveElementTransformMatrix($element);
        [$actualX, $actualY] = $resolver->applyTransformToPoint($matrix, 1.0, 1.0);

        self::assertEqualsWithDelta(4.0, $actualX, 0.0001);
        self::assertEqualsWithDelta(5.0, $actualY, 0.0001);
    }

    public function testResolveElementTransformMatrixFallsBackToIdentityForUnsupportedTransformText(): void
    {
        $resolver = new SvgTransformResolver();
        $element = $this->createNestedElement(['banana(10)']);

        self::assertSame([1.0, 0.0, 0.0, 1.0, 0.0, 0.0], $resolver->resolveElementTransformMatrix($element));
    }

    /**
     * @return iterable<string, array{transform: string, x: float, y: float, expectedPoint: array{0: float, 1: float}}>
     */
    public static function provideSingleTransformScenarios(): iterable
    {
        yield 'matrix operator' => [
            'transform' => 'matrix(1,2,3,4,5,6)',
            'x' => 1.0,
            'y' => 2.0,
            'expectedPoint' => [12.0, 16.0],
        ];

        yield 'translate operator' => [
            'transform' => 'translate(5,7)',
            'x' => 1.0,
            'y' => 2.0,
            'expectedPoint' => [6.0, 9.0],
        ];

        yield 'scale operator with implicit y scale' => [
            'transform' => 'scale(3)',
            'x' => 2.0,
            'y' => 4.0,
            'expectedPoint' => [6.0, 12.0],
        ];

        yield 'rotate operator around origin' => [
            'transform' => 'rotate(90)',
            'x' => 2.0,
            'y' => 0.0,
            'expectedPoint' => [0.0, 2.0],
        ];

        yield 'rotate operator around center' => [
            'transform' => 'rotate(90 1 1)',
            'x' => 2.0,
            'y' => 1.0,
            'expectedPoint' => [1.0, 2.0],
        ];

        yield 'skewX operator' => [
            'transform' => 'skewX(45)',
            'x' => 1.0,
            'y' => 2.0,
            'expectedPoint' => [3.0, 2.0],
        ];

        yield 'skewY operator' => [
            'transform' => 'skewY(45)',
            'x' => 1.0,
            'y' => 2.0,
            'expectedPoint' => [1.0, 3.0],
        ];
    }

    /**
     * @param list<string> $transforms
     */
    private function createNestedElement(array $transforms): DOMElement
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $svg = $document->createElement('svg');
        $document->appendChild($svg);

        $current = $svg;

        foreach ($transforms as $transform) {
            $next = $document->createElement('g');
            $next->setAttribute('transform', $transform);
            $current->appendChild($next);
            $current = $next;
        }

        $target = $document->createElement('path');
        $current->appendChild($target);

        return $target;
    }
}
