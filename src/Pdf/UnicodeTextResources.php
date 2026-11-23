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
     *     mappings: list<array{glyph: int, unicode: list<int>}>
     * }>
     */
    private array $selected;

    /** @var array<int, array{alias: string, cids: list<int>}> */
    private array $lineBindings;

    /**
     * @param list<LayoutLine> $lines
     * @param list<TrueTypeFont> $fonts
     */
    public function __construct(array $lines, array $fonts)
    {
        $shaper = new DirectGlyphShaper();
        $selected = [];
        $bindings = [];

        foreach ($lines as $index => $line) {
            if (preg_match('/[^\x00-\x7f]/', $line->text) !== 1) {
                continue;
            }
            $glyphs = $shaper->shape($line->text);
            $fontIndex = $this->selectFont($fonts, $glyphs);
            $alias = 'U' . ($fontIndex + 1);
            $mappings = $selected[$alias]['mappings'] ?? [];
            [$mappings, $cids] = $this->bindGlyphs($mappings, $glyphs);
            $selected[$alias] = ['font' => $fonts[$fontIndex], 'mappings' => $mappings];
            $bindings[$index] = ['alias' => $alias, 'cids' => $cids];
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
     * @param list<array{glyph: int, unicode: list<int>}> $mappings
     * @param list<array{glyph: int, unicode: list<int>}> $glyphs
     * @return array{list<array{glyph: int, unicode: list<int>}>, list<int>}
     */
    private function bindGlyphs(array $mappings, array $glyphs): array
    {
        $cids = [];
        foreach ($glyphs as $glyph) {
            $index = array_search($glyph, $mappings, true);
            if ($index === false) {
                $mappings[] = $glyph;
                $index = count($mappings) - 1;
            }
            if ($index >= 65535) {
                throw new InvalidArgumentException('Unicode CID mapping out of range.');
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
        $hex = '';
        foreach ($binding['cids'] as $cid) {
            $hex .= sprintf('%04X', $cid);
        }
        return '<' . $hex . '> Tj';
    }
}
