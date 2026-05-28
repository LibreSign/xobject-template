<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Integration;

use InvalidArgumentException;
use LibreSign\XObjectTemplate\Dto\CompileResult;

final readonly class XObjectPlacementCalculator
{
    public function fromWidth(
        CompileResult $result,
        float $targetWidth,
        float $x = 0.0,
        float $y = 0.0,
    ): XObjectPlacement {
        if ($targetWidth <= 0.0) {
            throw new InvalidArgumentException('Placement target width must be greater than zero.');
        }

        [$minX, $minY, $baseWidth, $baseHeight] = $this->resolveBoundingBox($result);
        $scale = $targetWidth / $baseWidth;

        return new XObjectPlacement(
            scaleX: $scale,
            scaleY: $scale,
            width: $baseWidth * $scale,
            height: $baseHeight * $scale,
            translateX: $x - ($minX * $scale),
            translateY: $y - ($minY * $scale),
        );
    }

    public function fromHeight(
        CompileResult $result,
        float $targetHeight,
        float $x = 0.0,
        float $y = 0.0,
    ): XObjectPlacement {
        if ($targetHeight <= 0.0) {
            throw new InvalidArgumentException('Placement target height must be greater than zero.');
        }

        [$minX, $minY, $baseWidth, $baseHeight] = $this->resolveBoundingBox($result);
        $scale = $targetHeight / $baseHeight;

        return new XObjectPlacement(
            scaleX: $scale,
            scaleY: $scale,
            width: $baseWidth * $scale,
            height: $baseHeight * $scale,
            translateX: $x - ($minX * $scale),
            translateY: $y - ($minY * $scale),
        );
    }

    public function fromScale(
        CompileResult $result,
        float $scale,
        float $x = 0.0,
        float $y = 0.0,
    ): XObjectPlacement {
        if ($scale <= 0.0) {
            throw new InvalidArgumentException('Placement scale must be greater than zero.');
        }

        [$minX, $minY, $baseWidth, $baseHeight] = $this->resolveBoundingBox($result);

        return new XObjectPlacement(
            scaleX: $scale,
            scaleY: $scale,
            width: $baseWidth * $scale,
            height: $baseHeight * $scale,
            translateX: $x - ($minX * $scale),
            translateY: $y - ($minY * $scale),
        );
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function resolveBoundingBox(CompileResult $result): array
    {
        [$minX, $minY, $maxX, $maxY] = $result->bbox;
        $width = $maxX - $minX;
        $height = $maxY - $minY;

        if ($width <= 0.0 || $height <= 0.0) {
            throw new InvalidArgumentException('CompileResult bbox must describe a positive area.');
        }

        return [$minX, $minY, $width, $height];
    }
}
