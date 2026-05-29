<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf;

/** @internal */
final readonly class ParsedPngImage
{
    public function __construct(
        public int $width,
        public int $height,
        public int $colorType,
        public string $idat,
    ) {
    }
}
