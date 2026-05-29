<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Layout;

final readonly class LayoutLine
{
    /**
     * @param array{x: float, y: float, width: float, height: float}|null $clipBox
     */
    public function __construct(
        public string $text,
        public float $x,
        public float $y,
        public float $fontSize,
        public string $fontAlias,
        public string $rgbColor,
        public float $wordSpacing = 0.0,
        public ?array $clipBox = null,
    ) {
    }
}
