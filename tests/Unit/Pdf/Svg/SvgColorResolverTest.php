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
use ReflectionMethod;

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
            'class' => '  primary   secondary  ',
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

    #[DataProvider('provideExtractValueFromStyleAttributeScenarios')]
    public function testExtractValueFromStyleAttributeReturnsRequestedProperty(
        string $style,
        string $property,
        ?string $expected,
        bool $useColorExtractor = false,
    ): void {
        $resolver = new SvgColorResolver();

        $result = $useColorExtractor
            ? $resolver->extractColorFromStyleAttribute($style, $property)
            : $resolver->extractValueFromStyleAttribute($style, $property);

        self::assertSame($expected, $result);
    }

    public function testResolveColorAttributeRemainsPublicForFactoryCollaborators(): void
    {
        $method = new ReflectionMethod(SvgColorResolver::class, 'resolveColorAttribute');

        self::assertTrue($method->isPublic());
    }

    /**
     * @return iterable<string, array{style: string, property: string, expected: ?string, useColorExtractor?: bool}>
     */
    public static function provideExtractValueFromStyleAttributeScenarios(): iterable
    {
        yield 'extract generic property' => [
            'style' => 'fill:#fff; stroke-width: 2.5 ;',
            'property' => 'stroke-width',
            'expected' => '2.5',
        ];

        yield 'extract fill color case-insensitive' => [
            'style' => ' FiLl : #fff ; ',
            'property' => 'fill',
            'expected' => '#fff',
            'useColorExtractor' => true,
        ];

        yield 'extract dotted property name' => [
            'style' => 'fill.opacity: 0.5',
            'property' => 'fill.opacity',
            'expected' => '0.5',
        ];

        yield 'extract value containing colon characters' => [
            'style' => 'fill:url(http://example.com/a:b.svg)',
            'property' => 'fill',
            'expected' => 'url(http://example.com/a:b.svg)',
        ];

        yield 'empty style returns null' => [
            'style' => '',
            'property' => 'fill',
            'expected' => null,
        ];

        yield 'missing property returns null' => [
            'style' => 'stroke:#000',
            'property' => 'fill',
            'expected' => null,
        ];
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
        yield 'hex with invalid prefix' => ['input' => 'x#abc', 'expected' => null];
        yield 'hex with invalid suffix' => ['input' => '#abcx', 'expected' => null];
        yield 'hex with invalid medium length' => ['input' => '#1234', 'expected' => null];
        yield 'rgb notation rejects negative channel' => ['input' => 'rgb(300,-1,12)', 'expected' => null];
        yield 'rgb notation valid' => ['input' => 'rgb(255, 0, 12)', 'expected' => '#ff000c'];
        yield 'rgb notation clamps overflowing red' => ['input' => 'rgb(256,0,0)', 'expected' => '#ff0000'];
        yield 'rgb notation preserves max green' => ['input' => 'rgb(0,255,0)', 'expected' => '#00ff00'];
        yield 'rgb notation clamps overflowing green' => ['input' => 'rgb(0,256,0)', 'expected' => '#00ff00'];
        yield 'rgb notation preserves zero blue' => ['input' => 'rgb(0,0,0)', 'expected' => '#000000'];
        yield 'rgb notation clamps overflowing blue' => ['input' => 'rgb(0,0,256)', 'expected' => '#0000ff'];
        yield 'rgb notation rejects prefixed content' => ['input' => 'prefix rgb(255,0,12)', 'expected' => null];
        yield 'rgb notation rejects suffixed content' => ['input' => 'rgb(255,0,12) suffix', 'expected' => null];
        yield 'rgb notation rejects missing closing parenthesis' => ['input' => 'rgb(255,0,12', 'expected' => null];
        yield 'rgb notation rejects missing opening marker' => ['input' => '255,0,12)', 'expected' => null];
        yield 'rgb notation rejects contaminated channel' => ['input' => 'rgb(12x,0,0)', 'expected' => null];
        yield 'named black' => ['input' => 'black', 'expected' => '#000000'];
        yield 'named white' => ['input' => 'white', 'expected' => '#ffffff'];
        yield 'named red' => ['input' => 'red', 'expected' => '#ff0000'];
        yield 'named green' => ['input' => 'green', 'expected' => '#008000'];
        yield 'named gray alias' => ['input' => 'grey', 'expected' => '#808080'];
        yield 'named gray canonical' => ['input' => 'gray', 'expected' => '#808080'];
        yield 'named blue' => ['input' => 'blue', 'expected' => '#0000ff'];
        yield 'named yellow' => ['input' => 'yellow', 'expected' => '#ffff00'];
        yield 'invalid color' => ['input' => 'chartreuse-ish', 'expected' => null];
    }

    #[DataProvider('provideNormalizeColorWhitespaceScenarios')]
    public function testNormalizeColorTrimsWhitespaceBeforeProcessing(string $input, ?string $expected): void
    {
        $resolver = new SvgColorResolver();

        self::assertSame($expected, $resolver->normalizeColor($input));
    }

    /**
     * @return iterable<string, array{input: string, expected: ?string}>
     */
    public static function provideNormalizeColorWhitespaceScenarios(): iterable
    {
        yield 'trim hex color' => ['input' => '  #aabbcc  ', 'expected' => '#aabbcc'];
        yield 'trim named color' => ['input' => '  black  ', 'expected' => '#000000'];
        yield 'trim rgb color' => ['input' => '  rgb(255, 0, 0)  ', 'expected' => '#ff0000'];
    }

    #[DataProvider('provideResolveFillColorClassExtractionScenarios')]
    public function testResolveFillColorHandlesClassExtraction(
        array $attributes,
        array $classColors,
        string $expected,
    ): void
    {
        $resolver = new SvgColorResolver();
        $element = $this->createElement('div', $attributes);

        $result = $resolver->resolveFillColor($element, $classColors);

        self::assertSame($expected, $result);
    }

    /**
     * @return iterable<string, array{attributes: array<string, string>, classColors: array<string, string>, expected: string}>
     */
    public static function provideResolveFillColorClassExtractionScenarios(): iterable
    {
        yield 'multiple spaces keep first matching class' => [
            'attributes' => ['class' => '  primary   secondary  tertiary  '],
            'classColors' => [
                'primary' => '#111111',
                'secondary' => '#222222',
                'tertiary' => '#333333',
            ],
            'expected' => '#111111',
        ];

        yield 'empty class falls back to default fill' => [
            'attributes' => ['class' => ''],
            'classColors' => ['primary' => '#ff0000'],
            'expected' => '#000000',
        ];
    }

    #[DataProvider('provideNormalizeColorRgbValidationScenarios')]
    public function testNormalizeColorValidatesRgbChannels(string $input, ?string $expected): void
    {
        $resolver = new SvgColorResolver();

        self::assertSame($expected, $resolver->normalizeColor($input));
    }

    /**
     * @return iterable<string, array{input: string, expected: ?string}>
     */
    public static function provideNormalizeColorRgbValidationScenarios(): iterable
    {
        yield 'accepts numeric rgb channels' => ['input' => 'rgb(255, 0, 0)', 'expected' => '#ff0000'];
        yield 'accepts rgb channels with spaces' => ['input' => 'rgb( 255 , 0 , 0 )', 'expected' => '#ff0000'];
        yield 'rejects non numeric channels' => ['input' => 'rgb(25a, 0, 0)', 'expected' => null];
        yield 'rejects negative channels' => ['input' => 'rgb(255, -1, 0)', 'expected' => null];
        yield 'clamps overflowing channels' => ['input' => 'rgb(256, 0, 0)', 'expected' => '#ff0000'];
        yield 'rejects alphanumeric suffix' => ['input' => 'rgb(123abc, 0, 0)', 'expected' => null];
        yield 'rejects alphanumeric prefix' => ['input' => 'rgb(abc123, 0, 0)', 'expected' => null];
        yield 'rejects embedded spaces inside channel digits' => ['input' => 'rgb(12 3, 0, 0)', 'expected' => null];
    }

    #[DataProvider('provideExtractColorFromStyleScenarios')]
    public function testExtractColorFromStyleAttributeHandlesExpectedInputs(string $style, ?string $expected): void
    {
        $resolver = new SvgColorResolver();

        $result = $resolver->extractColorFromStyleAttribute($style, 'fill');

        self::assertSame($expected, $result);
    }

    /**
     * @return iterable<string, array{style: string, expected: ?string}>
     */
    public static function provideExtractColorFromStyleScenarios(): iterable
    {
        yield 'extract from multiple declarations' => [
            'style' => 'fill: red; stroke: blue; opacity: 0.5;',
            'expected' => 'red',
        ];
        yield 'empty style returns null' => ['style' => '', 'expected' => null];
        yield 'whitespace style returns null' => ['style' => '   ', 'expected' => null];
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
