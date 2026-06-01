<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf\Svg;

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
