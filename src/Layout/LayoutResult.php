<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Layout;

final readonly class LayoutResult
{
    /**
     * @param list<LayoutLine> $lines
     * @param list<LayoutImage> $images
     */
    public function __construct(
        public array $lines,
        public array $images,
    ) {
    }
}
