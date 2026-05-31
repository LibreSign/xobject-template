<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf\Svg;

/**
 * Mutable state container for SVG path parsing.
 * Tracks current position, control points, and accumulated commands.
 *
 * @internal
 */
final class PathParsingState
{
    public function __construct(
        public float $currentX = 0.0,
        public float $currentY = 0.0,
        public float $subpathStartX = 0.0,
        public float $subpathStartY = 0.0,
        public ?float $lastCubicControlX = null,
        public ?float $lastCubicControlY = null,
        public ?float $prevQuadCpX = null,
        public ?float $prevQuadCpY = null,
        /** @var list<string> */
        public array $commands = [],
    ) {
    }
}
