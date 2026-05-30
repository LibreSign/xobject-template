<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf\Png;

use InvalidArgumentException;

/** @internal */
final readonly class PngColorTypeDescription
{
    /** @var 1|3 */
    public int $colorCount;

    /** @var positive-int */
    public int $bytesPerPixel;

    public function __construct(
        public string $colorSpace,
        int $colorCount,
        int $bytesPerPixel,
        public bool $hasAlpha,
    ) {
        if ($colorCount !== 1 && $colorCount !== 3) {
            throw new InvalidArgumentException('PNG color count must be 1 or 3.');
        }

        if ($bytesPerPixel < 1) {
            throw new InvalidArgumentException('PNG bytes per pixel must be positive.');
        }

        $expectedBytes = $colorCount + ($hasAlpha ? 1 : 0);
        if ($bytesPerPixel !== $expectedBytes) {
            throw new InvalidArgumentException('PNG color layout is inconsistent.');
        }

        $this->colorCount = $colorCount;
        $this->bytesPerPixel = $bytesPerPixel;
    }
}
