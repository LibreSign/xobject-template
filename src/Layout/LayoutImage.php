<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Layout;

final readonly class LayoutImage
{
    public function __construct(
        public string $alias,
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public string $source,
    ) {
    }
}
