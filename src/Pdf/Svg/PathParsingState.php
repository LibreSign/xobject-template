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
        public ?float $lastCubicControlX = null,
        public ?float $lastCubicControlY = null,
        public ?float $lastQuadraticControlX = null,
        public ?float $lastQuadraticControlY = null,
        /** @var list<string> */
        public array $commands = [],
    ) {
    }
}

/**
 * Context for path command processing.
 * Encapsulates transform and coordinate parameters.
 *
 * @internal
 */
final readonly class PathCommandContext
{
    /**
     * @param array{0:float,1:float,2:float,3:float,4:float,5:float} $transformMatrix
     */
    public function __construct(
        public array $transformMatrix,
        public float $minX,
        public float $maxY,
        public string $source,
    ) {
    }
}
