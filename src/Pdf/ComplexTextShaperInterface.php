<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf;

interface ComplexTextShaperInterface
{
    /**
     * Glyphs are ordered visually, with exact advances/offsets in thousandths of an em.
     *
     * @return list<array{
     *   glyph: int, glyphId: int, unicode: list<int>,
     *   advance: int, dx: int, dy: int, cluster: int, logical: int
     * }>
     */
    public function shape(string $text, TrueTypeFont $font): array;
}
