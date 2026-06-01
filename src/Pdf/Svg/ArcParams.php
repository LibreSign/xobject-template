<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace LibreSign\XObjectTemplate\Pdf\Svg;

/**
 * Internal value object grouping the common arc parameters.
 *
 * @internal
 */
final readonly class ArcParams
{
    /**
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        public float $fromX,
        public float $fromY,
        public float $toX,
        public float $toY,
        public float $radiusX,
        public float $radiusY,
        public float $cosTh,
        public float $sinTh,
        public int $largeArc,
        public int $sweep,
    ) {
    }

    public function withRadii(float $radiusX, float $radiusY): self
    {
        return new self(
            $this->fromX,
            $this->fromY,
            $this->toX,
            $this->toY,
            $radiusX,
            $radiusY,
            $this->cosTh,
            $this->sinTh,
            $this->largeArc,
            $this->sweep,
        );
    }
}
