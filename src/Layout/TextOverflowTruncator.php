<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Layout;

use LibreSign\XObjectTemplate\Pdf\StandardFontMetrics;

/** @internal */
final readonly class TextOverflowTruncator
{
    public function __construct(private StandardFontMetrics $fontMetrics)
    {
    }

    public function truncateWithEllipsis(
        string $text,
        float $maxWidth,
        string $fontAlias,
        float $fontSize,
    ): string {
        if ($this->fontMetrics->measureString($fontAlias, $fontSize, $text) <= $maxWidth) {
            return $text;
        }

        return $this->forceEllipsis($text, $maxWidth, $fontAlias, $fontSize);
    }

    public function forceEllipsis(
        string $text,
        float $maxWidth,
        string $fontAlias,
        float $fontSize,
    ): string {
        $ellipsis = '...';
        $ellipsisWidth = $this->fontMetrics->measureString($fontAlias, $fontSize, $ellipsis);
        $characters = $this->splitCharacters($text);

        foreach ($this->buildCandidates($characters) as $candidate) {
            if (($this->fontMetrics->measureString($fontAlias, $fontSize, $candidate) + $ellipsisWidth) <= $maxWidth) {
                return rtrim($candidate) . $ellipsis;
            }
        }

        return $ellipsis;
    }

    /**
     * @return list<string>
     */
    private function splitCharacters(string $text): array
    {
        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return $characters === false ? [] : $characters;
    }

    /**
     * @param list<string> $characters
     * @return list<string>
     */
    private function buildCandidates(array $characters): array
    {
        $candidates = [];
        $candidate = '';

        foreach ($characters as $character) {
            $candidate .= $character;
            $candidates[] = $candidate;
        }

        return array_reverse($candidates);
    }
}
