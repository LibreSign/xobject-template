<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf;

use LibreSign\XObjectTemplate\Layout\LayoutLine;
use LibreSign\XObjectTemplate\Pdf\ComplexTextShaperInterface;
use LibreSign\XObjectTemplate\Pdf\PdfEscaper;
use LibreSign\XObjectTemplate\Pdf\TrueTypeFont;
use LibreSign\XObjectTemplate\Pdf\UnicodeTextResources;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ComplexTextPositioningTest extends TestCase
{
    #[DataProvider('positionsProvider')]
    public function testPlacesShapedGlyphsInVisualPositionsButEmitsLogicalOrder(
        array $shaped,
        array $expected,
    ): void {
        $font = new TrueTypeFont('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf');
        $shaper = new class ($shaped) implements ComplexTextShaperInterface {
            public function __construct(private readonly array $glyphs)
            {
            }

            public function shape(string $text, TrueTypeFont $font): array
            {
                TestCase::assertSame('שלום', $text);
                return $this->glyphs;
            }

            public function shapeWithFonts(string $text, array $fonts): array
            {
                throw new \LogicException('This test only exercises one font.');
            }
        };
        $resources = new UnicodeTextResources(
            [new LayoutLine('שלום', 10.0, 20.0, 12.0, 'F1', '#000000')],
            [$font],
            $shaper,
        );

        $stream = $resources->textOperator(0, 'שלום', new PdfEscaper());
        $previousOffset = -1;
        foreach ($expected as $command) {
            $offset = strpos($stream, (string) $command);
            self::assertNotFalse($offset, 'Each positioned glyph must be present in the PDF stream.');
            self::assertGreaterThan($previousOffset, $offset, 'Glyphs must be emitted in logical Unicode order.');
            $previousOffset = $offset;
        }
        self::assertSame('U1', $resources->aliasFor(0, 'F1'));
    }

    /** @return iterable<string, array{shaped: array, expected: list<string>}> */
    public static function positionsProvider(): iterable
    {
        $g = static fn (int $scalar, int $logical, int $advance, int $dx, int $dy): array => [
            'glyph' => $scalar,
            'glyphId' => 3,
            'unicode' => [$scalar],
            'advance' => $advance,
            'dx' => $dx,
            'dy' => $dy,
            'cluster' => $logical,
            'logical' => $logical,
        ];
        yield 'glyphs in same cluster preserve their visual order' => [
            'shaped' => [
                $g(0x05E9, 0, 350, 0, 0),
                $g(0x05E9, 0, 0, 35, 160),
                $g(0x05DC, 1, 300, -40, 0),
                $g(0x05D5, 2, 500, 0, 0),
                $g(0x05DD, 3, 450, 0, 0),
            ],
            'expected' => [
                '1 0 0 1 10.000000 20.000000 Tm <0001> Tj',
                '1 0 0 1 14.620000 21.920000 Tm <0001> Tj',
                '1 0 0 1 13.720000 20.000000 Tm <0002> Tj',
                '1 0 0 1 17.800000 20.000000 Tm <0003> Tj',
                '1 0 0 1 23.800000 20.000000 Tm <0004> Tj',
            ],
        ];
        yield 'RTL glyph ordering, variable advances and offsets' => [
            'shaped' => [
                $g(0x05DD, 3, 500, 50, 75),
                $g(0x05D5, 2, 700, -25, -50),
                $g(0x05DC, 1, 600, 0, 0),
                $g(0x05E9, 0, 800, 0, 0),
            ],
            'expected' => [
                '1 0 0 1 31.600000 20.000000 Tm <0004> Tj',
                '1 0 0 1 24.400000 20.000000 Tm <0003> Tj',
                '1 0 0 1 15.700000 19.400000 Tm <0002> Tj',
                '1 0 0 1 10.600000 20.900000 Tm <0001> Tj',
            ],
        ];
    }
}
