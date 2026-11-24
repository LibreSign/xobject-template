<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf;

use InvalidArgumentException;
use LibreSign\XObjectTemplate\Layout\LayoutLine;

/** @internal Immutable per-layout Unicode resource preparation. */
final readonly class UnicodeTextResources
{
    /**
     * @var array<string, array{
     *     font: TrueTypeFont,
     *     mappings: list<array{glyph: int, unicode: list<int>, glyphId?: int}>
     * }>
     */
    private array $selected;

    /**
     * @var array<int, array{
     *   alias: string, cids: list<int>, complex: bool,
     *   positions: list<array{
     *     glyph: int, glyphId: int, unicode: list<int>, advance: int,
     *     dx: int, dy: int, cluster: int, logical: int
     *   }>,
     *   x: float, y: float, fontSize: float, text: string
     * }>
     */
    private array $lineBindings;

    /**
     * @param list<LayoutLine> $lines
     * @param list<TrueTypeFont> $fonts
     */
    public function __construct(array $lines, array $fonts)
    {
        $shaper = new DirectGlyphShaper();
        $codec = new UnicodeScalarCodec();
        $selected = [];
        /** @var array<int, array{
         * alias: string, cids: list<int>, complex: bool,
         * positions: list<array{
         *   glyph: int, glyphId: int, unicode: list<int>, advance: int,
         *   dx: int, dy: int, cluster: int, logical: int
         * }>,
         * x: float, y: float, fontSize: float, text: string
         * }> $bindings
         */
        $bindings = [];

        foreach ($lines as $index => $line) {
            if (preg_match('/[^\x00-\x7f]/', $line->text) !== 1) {
                continue;
            }
            $unicodeGlyphs = [];
            foreach ($codec->decode($line->text) as $codepoint) {
                $unicodeGlyphs[] = ['glyph' => $codepoint, 'unicode' => [$codepoint]];
            }
            $fontIndex = $this->selectFont($fonts, $unicodeGlyphs);
            $complex = preg_match('/[\p{Arabic}\p{Hebrew}\p{M}]/u', $line->text) === 1;
            $complexGlyphs = $complex
                ? (new HarfBuzzComplexTextShaper())->shape($line->text, $fonts[$fontIndex])
                : [];
            /** @var list<array{glyph: int, unicode: list<int>, glyphId?: int}> $glyphs */
            $glyphs = $complex ? $complexGlyphs : $shaper->shape($line->text);
            $alias = 'U' . ($fontIndex + 1);
            /** @var list<array{glyph: int, unicode: list<int>, glyphId?: int}> $mappings */
            $mappings = $selected[$alias]['mappings'] ?? [];
            [$mappings, $cids] = $this->bindGlyphs($mappings, $glyphs);
            $selected[$alias] = ['font' => $fonts[$fontIndex], 'mappings' => $mappings];
            $bindings[$index] = [
                'alias' => $alias,
                'cids' => $cids,
                'complex' => $complex,
                'positions' => $complexGlyphs,
                'x' => $line->x,
                'y' => $line->y,
                'fontSize' => $line->fontSize,
                'text' => $line->text,
            ];
        }
        $this->selected = $selected;
        $this->lineBindings = $bindings;
    }

    /**
     * @param list<TrueTypeFont> $fonts
     * @param list<array{glyph: int, unicode: list<int>}> $glyphs
     */
    private function selectFont(array $fonts, array $glyphs): int
    {
        foreach ($fonts as $fontIndex => $font) {
            if ($this->coversGlyphs($font, $glyphs)) {
                return $fontIndex;
            }
        }
        throw new InvalidArgumentException(
            'Unicode text requires an embedded TrueType font covering every shaped glyph.',
        );
    }

    /** @param list<array{glyph: int, unicode: list<int>}> $glyphs */
    private function coversGlyphs(TrueTypeFont $font, array $glyphs): bool
    {
        foreach ($glyphs as $glyph) {
            if ($font->glyphFor($glyph['glyph']) === 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param list<array{glyph: int, unicode: list<int>, glyphId?: int}> $mappings
     * @param list<array{glyph: int, unicode: list<int>, glyphId?: int}> $glyphs
     * @return array{list<array{glyph: int, unicode: list<int>, glyphId?: int}>, list<int>}
     */
    private function bindGlyphs(array $mappings, array $glyphs): array
    {
        $cids = [];
        foreach ($glyphs as $glyph) {
            $mapping = ['glyph' => $glyph['glyph'], 'unicode' => $glyph['unicode']];
            if (isset($glyph['glyphId'])) {
                $mapping['glyphId'] = $glyph['glyphId'];
            }
            $index = array_search($mapping, $mappings, true);
            if ($index === false) {
                $mappings[] = $mapping;
                $index = count($mappings) - 1;
            }
            $cids[] = $index + 1;
        }
        return [$mappings, $cids];
    }

    /** @return array<string, array<string, mixed>> */
    public function fonts(): array
    {
        $resources = [];
        foreach ($this->selected as $alias => $record) {
            $resources[$alias] = [
                '__TrueTypeFont' => $record['font'],
                '__Mappings' => $record['mappings'],
            ];
        }
        return $resources;
    }

    public function aliasFor(int $lineIndex, string $original): string
    {
        return $this->lineBindings[$lineIndex]['alias'] ?? $original;
    }

    public function textOperator(int $lineIndex, string $text, PdfEscaper $escaper): string
    {
        $binding = $this->lineBindings[$lineIndex] ?? null;
        if ($binding === null) {
            return sprintf('(%s) Tj', $escaper->escapeLiteralString($text));
        }
        if ($binding['complex']) {
            return $this->complexTextOperator($binding);
        }
        $hex = '';
        foreach ($binding['cids'] as $cid) {
            $hex .= sprintf('%04X', $cid);
        }
        return '<' . $hex . '> Tj';
    }
    /**
     * @param array{alias: string, cids: list<int>, complex: bool,
     * positions: list<array{
     *   glyph: int, glyphId: int, unicode: list<int>, advance: int,
     *   dx: int, dy: int, cluster: int, logical: int
     * }>,
     * x: float, y: float, fontSize: float, text: string} $binding
     */
    private function complexTextOperator(array $binding): string
    {
        $codec = new UnicodeScalarCodec();
        $unicodeHex = 'FEFF';
        foreach ($codec->decode($binding['text']) as $scalar) {
            $unicodeHex .= $codec->encodeUtf16Hex($scalar);
        }

        $commands = ['/Span << /ActualText <' . $unicodeHex . '> >> BDC'];
        $advance = 0;
        $positions = [];
        foreach ($binding['cids'] as $index => $cid) {
            $glyph = $binding['positions'][$index];
            $scale = $binding['fontSize'] / 1000.0;
            $positions[] = [
                'logical' => $glyph['logical'],
                'visual' => $index,
                'x' => $binding['x'] + ($advance + $glyph['dx']) * $scale,
                'y' => $binding['y'] + $glyph['dy'] * $scale,
                'cid' => $cid,
            ];
            $advance += $glyph['advance'];
        }
        // PDF text operators may be emitted in logical order, regardless of
        // absolute glyph placement, so text extraction retains original order.
        usort($positions, static fn (array $first, array $second): int =>
            ($first['logical'] <=> $second['logical']) ?: ($first['visual'] <=> $second['visual']));
        foreach ($positions as $position) {
            $commands[] = sprintf(
                '1 0 0 1 %F %F Tm <%04X> Tj',
                $position['x'],
                $position['y'],
                $position['cid'],
            );
        }
        $commands[] = 'EMC';
        return implode("\n", $commands);
    }
}
