<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Layout;

use LibreSign\XObjectTemplate\Css\InlineStyleParser;
use LibreSign\XObjectTemplate\Layout\LayoutStyleResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LayoutStyleResolverTest extends TestCase
{
        /**
         * @return iterable<string, array{0: string, 1: float}>
         */
        public static function toPointsProvider(): iterable
        {
            yield 'uppercase px unit converts to points' => ['10PX', 7.5];
            yield 'unitless value stays absolute' => ['10', 10.0];
            yield 'decimal px value preserves precision' => ['12.5PX', 9.375];
        }
    
        /**
         * @return iterable<string, array{0: string, 1: float, 2: float}>
         */
        public static function relativeDimensionProvider(): iterable
        {
            yield 'percentage uses provided reference' => [' 25% ', 80.0, 20.0];
            yield 'uppercase px unit converts through point normalization' => [' 10PX ', 80.0, 7.5];
            yield 'whitespace resolves to zero' => [' ', 80.0, 0.0];
            yield 'unitless decimals remain absolute' => ['12.5', 80.0, 12.5];
        }
    
        /**
         * @return iterable<string, array{0: string, 1: float, 2: float, 3: array{top: float, right: float, bottom: float, left: float}}>
         */
        public static function relativeBoxSpacingProvider(): iterable
        {
            yield 'single percentage applies to all sides' => [
                '10%',
                200.0,
                100.0,
                ['top' => 10.0, 'right' => 20.0, 'bottom' => 10.0, 'left' => 20.0],
            ];
    
            yield 'two-value shorthand mirrors horizontal slot' => [
                '10% 20%',
                200.0,
                100.0,
                ['top' => 10.0, 'right' => 40.0, 'bottom' => 10.0, 'left' => 40.0],
            ];
    
            yield 'three-value shorthand copies second token to left' => [
                '10% 20% 30%',
                200.0,
                100.0,
                ['top' => 10.0, 'right' => 40.0, 'bottom' => 30.0, 'left' => 40.0],
            ];
    
            yield 'four-value shorthand keeps every slot distinct' => [
                '10% 20% 30% 40%',
                200.0,
                100.0,
                ['top' => 10.0, 'right' => 40.0, 'bottom' => 30.0, 'left' => 80.0],
            ];
    
            yield 'mixed units normalize points and percentages together' => [
                '8PX 10% 12PX 5%',
                200.0,
                100.0,
                ['top' => 6.0, 'right' => 20.0, 'bottom' => 9.0, 'left' => 10.0],
            ];
    
            yield 'whitespace input returns zero slots' => [
                " \t\n ",
                200.0,
                100.0,
                ['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0],
            ];
        }
    
        /**
         * @return iterable<string, array{0: string, 1: bool}>
         */
        public static function absolutePositionProvider(): iterable
        {
            yield 'trimmed uppercase absolute is detected' => ['position: ABSOLUTE ', true];
            yield 'relative positioning is not treated as absolute' => ['position: relative', false];
            yield 'missing position stays non-absolute' => ['display:flex', false];
        }
    
        /**
         * @return iterable<string, array{0: string, 1: string, 2: string}>
         */
        public static function fontAliasProvider(): iterable
        {
            yield 'semibold courier maps to bold courier alias' => ['Courier New', '600', 'F6'];
            yield 'bold times maps to bold times alias' => ['Times New Roman', 'BOLD', 'F4'];
            yield 'weights below threshold keep regular helvetica alias' => ['Helvetica', '599', 'F1'];
            yield 'quoted times family still resolves to times alias' => ['"Times New Roman", serif', '400', 'F3'];
            yield 'bolder courier family keeps monospace alias' => ['Courier, monospace', 'bolder', 'F6'];
            yield 'bold helvetica fallback uses bold helvetica alias' => ['Helvetica, Arial, sans-serif', '700', 'F2'];
        }
    
        #[DataProvider('toPointsProvider')]
        public function testToPointsNormalizesUnitsAndPrecision(string $value, float $expectedPoints): void
        {
            $resolver = new LayoutStyleResolver();
    
            self::assertSame($expectedPoints, $resolver->toPoints($value));
        }
    
        #[DataProvider('relativeDimensionProvider')]
        public function testResolveRelativeDimensionUsesExpectedReferenceRule(
            string $value,
            float $reference,
            float $expectedDimension,
        ): void {
            $resolver = new LayoutStyleResolver();
    
            self::assertSame($expectedDimension, $resolver->resolveRelativeDimension($value, $reference));
        }
    
        #[DataProvider('relativeBoxSpacingProvider')]
        public function testParseBoxSpacingRelativeExpandsShorthandSlots(
            string $value,
            float $widthReference,
            float $heightReference,
            array $expectedSpacing,
        ): void {
            $resolver = new LayoutStyleResolver();
    
            self::assertSame($expectedSpacing, $resolver->parseBoxSpacingRelative($value, $widthReference, $heightReference));
        }
    
        #[DataProvider('absolutePositionProvider')]
        public function testIsAbsolutelyPositionedNormalizesWhitespaceAndCase(
            string $inlineStyle,
            bool $expectedAbsolute,
        ): void {
            $parser = new InlineStyleParser();
            $resolver = new LayoutStyleResolver();
    
            self::assertSame($expectedAbsolute, $resolver->isAbsolutelyPositioned($parser->parse($inlineStyle)));
        }
    
        #[DataProvider('fontAliasProvider')]
        public function testResolveFontAliasMapsSupportedPdfFamiliesAndWeights(
            string $fontFamily,
            string $fontWeight,
            string $expectedAlias,
        ): void {
            $resolver = new LayoutStyleResolver();
    
            self::assertSame($expectedAlias, $resolver->resolveFontAlias($fontFamily, $fontWeight));
        }
}
