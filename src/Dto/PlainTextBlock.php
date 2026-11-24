<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Dto;

use InvalidArgumentException;

/** A literal text box in points, relative to the top-left of the canvas. */
final readonly class PlainTextBlock
{
    /**
     * @param 'left'|'center'|'right' $align
     * @param 'top'|'center' $verticalAlign
     */
    public function __construct(
        public string $text,
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public float $fontSize = 10.0,
        public string $align = 'left',
        public string $verticalAlign = 'top',
    ) {
        foreach ([$x, $y, $width, $height, $fontSize] as $value) {
            if (!is_finite($value)) {
                throw new InvalidArgumentException('Text box geometry must be finite.');
            }
        }
        if ($width <= 0.0 || $height <= 0.0 || $fontSize <= 0.0) {
            throw new InvalidArgumentException('Text box dimensions and font size must be positive.');
        }
    }
}
