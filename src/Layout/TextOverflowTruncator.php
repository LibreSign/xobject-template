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
        $characters = $this->splitCharacters($text);

        while ($characters !== []) {
            $candidate = implode('', $characters) . $ellipsis;
            if ($this->fontMetrics->measureString($fontAlias, $fontSize, $candidate) <= $maxWidth) {
                return rtrim(implode('', $characters)) . $ellipsis;
            }

            array_pop($characters);
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
}
