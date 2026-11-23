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
    /** @var array<string, array{font: TrueTypeFont, mappings: list<array{glyph: int, unicode: list<int>}>>} */
    private array $selected;
    /** @var array<int, array{alias: string, cids: list<int>}> */
    private array $lineBindings;

    /**
     * @param list<LayoutLine> $lines
     * @param list<TrueTypeFont> $fonts
     */
    public function __construct(array $lines, array $fonts)
    {
        $shaper = new ArabicPresentationShaper();
        $selected = [];
        $bindings = [];
        foreach ($lines as $index => $line) {
            if (!preg_match('/[^\x00-\x7f]/', $line->text)) {
                continue;
            }

            $glyphs = $shaper->shape(TrueTypeFont::codepoints($line->text));
            $found = null;
            foreach ($fonts as $fontIndex => $font) {
                foreach ($glyphs as $entry) {
                    if ($font->glyphFor($entry['glyph']) === 0) {
                        continue 2;
                    }
                }
                $found = $fontIndex;
                break;
            }
            if ($found === null) {
                throw new InvalidArgumentException(
                    'Unicode text requires an embedded TrueType font covering every shaped glyph.',
                );
            }

            $alias = 'U' . ($found + 1);
            $record = $selected[$alias] ?? ['font' => $fonts[$found], 'mappings' => []];
            $cids = [];
            foreach ($glyphs as $entry) {
                $cid = array_search($entry, $record['mappings'], true);
                if ($cid === false) {
                    $record['mappings'][] = $entry;
                    $cid = count($record['mappings']) - 1;
                }
                if ($cid >= 65535) {
                    throw new InvalidArgumentException('Unicode CID mapping out of range.');
                }
                $cids[] = $cid + 1;
            }
            $selected[$alias] = $record;
            $bindings[$index] = ['alias' => $alias, 'cids' => $cids];
        }
        $this->selected = $selected;
        $this->lineBindings = $bindings;
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
