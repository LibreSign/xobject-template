<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf;

use InvalidArgumentException;

/**
 * Direct Unicode-to-glyph mapping for scripts without contextual shaping.
 *
 * @internal This is intentionally NOT an Arabic/BiDi/OpenType shaper.
 */
final readonly class DirectGlyphShaper
{
    public function __construct(private UnicodeScalarCodec $codec = new UnicodeScalarCodec())
    {
    }

    /**
     * @return list<array{glyph: int, unicode: list<int>}>
     */
    public function shape(string $text): array
    {
        if (preg_match('/[\p{Hebrew}\p{Arabic}\p{M}]/u', $text) === 1) {
            throw new InvalidArgumentException(
                'Complex-script text requires a full bidirectional/OpenType shaping engine.',
            );
        }
        $glyphs = [];
        foreach ($this->codec->decode($text) as $codepoint) {
            $glyphs[] = ['glyph' => $codepoint, 'unicode' => [$codepoint]];
        }
        return $glyphs;
    }
}
