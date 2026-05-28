<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf;

final readonly class EmbeddedPdfImage
{
    /**
     * @param array<string, mixed> $dictionary
     */
    public function __construct(
        public array $dictionary,
        public string $stream,
        public ?self $softMask = null,
    ) {
    }
}
