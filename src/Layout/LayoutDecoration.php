<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Layout;

final readonly class LayoutDecoration
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public ?string $fillColor = null,
        public ?string $strokeColor = null,
        public float $strokeWidth = 0.0,
        public float $borderRadius = 0.0,
    ) {
    }
}
