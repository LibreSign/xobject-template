<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf;

use InvalidArgumentException;
use LibreSign\XObjectTemplate\Layout\LayoutLine;

/**
 * @internal Immutable per-layout Unicode resource preparation.
 * @psalm-import-type Glyph from UnicodeTextPlanner
 */
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
     *   alias: string, cids: list<int>, aliases: list<string>, complex: bool,
     *   positions: list<Glyph>, x: float, y: float, fontSize: float, text: string
     * }>
     */
    private array $lineBindings;

    /**
     * @param list<LayoutLine> $lines
     * @param list<TrueTypeFont> $fonts
     */
    public function __construct(
        array $lines,
        array $fonts,
        private ComplexTextShaperInterface $complexShaper = new HarfBuzzComplexTextShaper(),
    ) {
        $planner = new UnicodeTextPlanner($this->complexShaper);
        $selected = [];
        $bindings = [];

        foreach ($lines as $index => $line) {
            if (preg_match('/[^\x00-\x7f]/', $line->text) !== 1) {
                continue;
            }
            $plan = $planner->plan($line->text, $fonts);
            if ($plan === null) {
                throw new InvalidArgumentException(
                    'Unicode text requires an embedded TrueType font covering every shaped glyph.',
                );
            }
            $cids = [];
            $aliases = [];
            foreach ($plan['glyphs'] as $glyph) {
                $fontIndex = $glyph['fontIndex'];
                $alias = 'U' . ($fontIndex + 1);
                $mappings = $selected[$alias]['mappings'] ?? [];
                $cids[] = $this->bindGlyph($mappings, $glyph);
                $aliases[] = $alias;
                $selected[$alias] = ['font' => $fonts[$fontIndex], 'mappings' => $mappings];
            }
            $bindings[$index] = [
                'alias' => $aliases[0],
                'cids' => $cids,
                'aliases' => $aliases,
                'complex' => $plan['positioned'],
                'positions' => $plan['glyphs'],
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
     * @param list<array{glyph: int, unicode: list<int>, glyphId?: int}> $mappings
     * @param-out list<array{glyph: int, unicode: list<int>, glyphId?: int}> $mappings
     * @param Glyph $glyph
     */
    private function bindGlyph(array &$mappings, array $glyph): int
    {
        $mapping = ['glyph' => $glyph['glyph'], 'unicode' => $glyph['unicode']];
        if (isset($glyph['glyphId'])) {
            $mapping['glyphId'] = $glyph['glyphId'];
        }
        $index = array_search($mapping, $mappings, true);
        if ($index === false) {
            $mappings[] = $mapping;
            $index = count($mappings) - 1;
        }
        return $index + 1;
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
     * @param array{alias: string, cids: list<int>, aliases: list<string>, complex: bool,
     * positions: list<Glyph>, x: float, y: float, fontSize: float, text: string} $binding
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
                'alias' => $binding['aliases'][$index],
            ];
            $advance += $glyph['advance'];
        }
        // PDF text operators may be emitted in logical order, regardless of
        // absolute glyph placement, so text extraction retains original order.
        usort($positions, static fn (array $first, array $second): int =>
            ($first['logical'] <=> $second['logical']) ?: ($first['visual'] <=> $second['visual']));
        $currentAlias = $binding['alias'];
        foreach ($positions as $position) {
            if ($position['alias'] !== $currentAlias) {
                $commands[] = sprintf('/%s %F Tf', $position['alias'], $binding['fontSize']);
                $currentAlias = $position['alias'];
            }
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
