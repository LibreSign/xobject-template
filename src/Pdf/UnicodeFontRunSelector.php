<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf;

/** @internal Keep a single font where possible; never split a grapheme across fonts. */
final readonly class UnicodeFontRunSelector
{
    public function __construct(private UnicodeScalarCodec $codec = new UnicodeScalarCodec())
    {
    }

    /**
     * @param list<TrueTypeFont> $fonts
     * @return list<array{fontIndex: int, text: string, start: int}>|null
     */
    public function runs(string $text, array $fonts): ?array
    {
        $fontIndex = $this->findFont($fonts, $this->codec->decode($text));
        if ($fontIndex !== null) {
            return [['fontIndex' => $fontIndex, 'text' => $text, 'start' => 0]];
        }

        preg_match_all('/\X/u', $text, $matches);
        /** @var list<array{fontIndex: int, text: string, start: int}> $runs */
        $runs = [];
        $start = 0;
        foreach ($matches[0] as $cluster) {
            $scalars = $this->codec->decode($cluster);
            $fontIndex = $this->findFont($fonts, $scalars);
            if ($fontIndex === null) {
                return null;
            }
            $clusterStart = $start;
            $start += count($scalars);
            $last = array_key_last($runs);
            if ($last !== null && $runs[$last]['fontIndex'] === $fontIndex) {
                $runs[$last]['text'] .= $cluster;
                continue;
            }
            $runs[] = ['fontIndex' => $fontIndex, 'text' => $cluster, 'start' => $clusterStart];
        }
        // Updating the last existing entry cannot create gaps in the list.
        /** @var list<array{fontIndex: int, text: string, start: int}> $runs */
        return $runs;
    }

    /**
     * @param list<TrueTypeFont> $fonts
     * @param list<int> $codepoints
     */
    private function findFont(array $fonts, array $codepoints): ?int
    {
        foreach ($fonts as $fontIndex => $font) {
            foreach ($codepoints as $codepoint) {
                if ($font->glyphFor($codepoint) === 0) {
                    continue 2;
                }
            }
            return $fontIndex;
        }
        return null;
    }
}
