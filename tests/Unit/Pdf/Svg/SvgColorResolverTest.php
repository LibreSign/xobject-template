<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf\Svg;

use DOMDocument;
use DOMElement;
use LibreSign\XObjectTemplate\Pdf\Svg\SvgColorResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SvgColorResolverTest extends TestCase
{
    public function testResolveFillColorPrefersInlineAttributeAndTreatsNoneAsTransparent(): void
    {
        $resolver = new SvgColorResolver();
        $element = $this->createElement('path', [
            'fill' => ' #AaBbCc ',
            'style' => 'fill:#ff0000',
            'class' => 'accent',
        ]);

        self::assertSame('#aabbcc', $resolver->resolveFillColor($element, ['accent' => '#123456']));

        $element->setAttribute('fill', 'none');

        self::assertNull($resolver->resolveFillColor($element, ['accent' => '#123456']));
    }

    public function testResolveFillColorFallsBackToStyleClassAncestorAndDefault(): void
    {
        $resolver = new SvgColorResolver();

        $styleElement = $this->createElement('path', [
            'style' => 'stroke:#fff; fill: rgb(10,20,30);',
        ]);
        self::assertSame('rgb(10,20,30)', $resolver->resolveFillColor($styleElement, []));

        $classElement = $this->createElement('path', [
            'class' => 'primary secondary',
        ]);
        self::assertSame('#112233', $resolver->resolveFillColor($classElement, ['secondary' => '#112233']));

        $ancestor = $this->createElement('g', ['style' => 'fill:#abcdef']);
        $document = $ancestor->ownerDocument;
        self::assertInstanceOf(DOMDocument::class, $document);
        $descendant = $document->createElement('path');
        $ancestor->appendChild($descendant);
        self::assertSame('#abcdef', $resolver->resolveFillColor($descendant, []));

        $fallback = $this->createElement('path');
        self::assertSame('#000000', $resolver->resolveFillColor($fallback, []));
    }

    public function testResolveStrokeColorFallsBackToClassAndAncestorButDefaultsToNull(): void
    {
        $resolver = new SvgColorResolver();

        $classElement = $this->createElement('line', ['class' => 'outline']);
        self::assertSame('#ff0000', $resolver->resolveStrokeColor($classElement, ['outline' => '#ff0000']));

        $ancestor = $this->createElement('g', ['stroke' => 'rgb(0,128,255)']);
        $document = $ancestor->ownerDocument;
        self::assertInstanceOf(DOMDocument::class, $document);
        $descendant = $document->createElement('line');
        $ancestor->appendChild($descendant);
        self::assertSame('#0080ff', $resolver->resolveStrokeColor($descendant, []));

        $noneByClass = $this->createElement('line', ['class' => 'ghost']);
        self::assertNull($resolver->resolveStrokeColor($noneByClass, ['ghost' => 'none']));

        $fallback = $this->createElement('line');
        self::assertNull($resolver->resolveStrokeColor($fallback, []));
    }

    public function testExtractValueFromStyleAttributeReturnsRequestedProperty(): void
    {
        $resolver = new SvgColorResolver();

        self::assertSame('2.5', $resolver->extractValueFromStyleAttribute('fill:#fff; stroke-width: 2.5 ;', 'stroke-width'));
        self::assertSame('#fff', $resolver->extractColorFromStyleAttribute(' FiLl : #fff ; ', 'fill'));
        self::assertNull($resolver->extractValueFromStyleAttribute('', 'fill'));
        self::assertNull($resolver->extractValueFromStyleAttribute('stroke:#000', 'fill'));
    }

    #[DataProvider('provideNormalizeColorScenarios')]
    public function testNormalizeColorSupportsExpectedFormats(string $input, ?string $expected): void
    {
        $resolver = new SvgColorResolver();

        self::assertSame($expected, $resolver->normalizeColor($input));
    }

    /**
     * @return iterable<string, array{input: string, expected: ?string}>
     */
    public static function provideNormalizeColorScenarios(): iterable
    {
        yield 'empty string' => ['input' => '  ', 'expected' => null];
        yield 'none sentinel' => ['input' => 'none', 'expected' => 'none'];
        yield 'short hex' => ['input' => '#abc', 'expected' => '#abc'];
        yield 'long hex uppercased and trimmed' => ['input' => ' #AABBCC ', 'expected' => '#aabbcc'];
        yield 'rgb notation with clamping' => ['input' => 'rgb(300,-1,12)', 'expected' => null];
        yield 'rgb notation valid' => ['input' => 'rgb(255, 0, 12)', 'expected' => '#ff000c'];
        yield 'named gray alias' => ['input' => 'grey', 'expected' => '#808080'];
        yield 'named blue' => ['input' => 'blue', 'expected' => '#0000ff'];
        yield 'invalid color' => ['input' => 'chartreuse-ish', 'expected' => null];
    }

    private function createElement(string $name, array $attributes = []): DOMElement
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $element = $document->createElement($name);
        $document->appendChild($element);

        foreach ($attributes as $attribute => $value) {
            $element->setAttribute($attribute, $value);
        }

        return $element;
    }
}
