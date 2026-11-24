<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf;

/**
 * @internal Use the same font selection and advances for measurement and drawing.
 * @psalm-type Glyph = array{
 *   fontIndex: int, glyph: int, glyphId?: int, unicode: list<int>,
 *   advance: int, dx: int, dy: int, cluster: int, logical: int
 * }
 */
final readonly class UnicodeTextPlanner
{
    public function __construct(
        private ComplexTextShaperInterface $complexShaper = new HarfBuzzComplexTextShaper(),
        private UnicodeFontRunSelector $selector = new UnicodeFontRunSelector(),
    ) {
    }

    /**
     * @param list<TrueTypeFont> $fonts
     * @return array{positioned: bool, glyphs: list<Glyph>}|null
     */
    public function plan(string $text, array $fonts): ?array
    {
        $runs = $this->selector->runs($text, $fonts);
        if ($runs === null) {
            return null;
        }

        $complex = preg_match('/[\p{Arabic}\p{Hebrew}\p{M}]/u', $text) === 1;
        if ($complex && count($runs) > 1) {
            return ['positioned' => true, 'glyphs' => $this->complexShaper->shapeWithFonts($text, $fonts)];
        }

        $glyphs = [];
        foreach ($runs as $run) {
            $fontIndex = $run['fontIndex'];
            $font = $fonts[$fontIndex];
            $shaped = $complex
                ? $this->complexShaper->shape($run['text'], $font)
                : $this->directGlyphs($run['text'], $font);
            foreach ($shaped as $glyph) {
                $glyph['fontIndex'] = $fontIndex;
                $glyph['logical'] += $run['start'];
                $glyphs[] = $glyph;
            }
        }
        return ['positioned' => $complex || count($runs) > 1, 'glyphs' => $glyphs];
    }

    /**
     * @return list<array{
     *   glyph: int, unicode: list<int>, advance: int, dx: int, dy: int, cluster: int, logical: int
     * }>
     */
    private function directGlyphs(string $text, TrueTypeFont $font): array
    {
        $result = [];
        foreach ((new DirectGlyphShaper())->shape($text) as $index => $glyph) {
            $result[] = $glyph + [
                'advance' => $font->widthForGlyph($font->glyphFor($glyph['glyph'])),
                'dx' => 0,
                'dy' => 0,
                'cluster' => $index,
                'logical' => $index,
            ];
        }
        return $result;
    }
}
