<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Integration;

use InvalidArgumentException;

final readonly class XObjectPlacement
{
    public function __construct(
        public float $scaleX,
        public float $scaleY,
        public float $width,
        public float $height,
        public float $translateX,
        public float $translateY,
    ) {
    }

    public function toPdfCommand(string $alias): string
    {
        $normalizedAlias = ltrim(trim($alias), '/');
        if ($normalizedAlias === '') {
            throw new InvalidArgumentException('Placement alias must not be empty.');
        }

        return sprintf(
            'q %s 0 0 %s %s %s cm /%s Do Q',
            $this->formatNumber($this->scaleX),
            $this->formatNumber($this->scaleY),
            $this->formatNumber($this->translateX),
            $this->formatNumber($this->translateY),
            $normalizedAlias,
        );
    }

    private function formatNumber(float $value): string
    {
        $formatted = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
        if ($formatted === '' || $formatted === '-0') {
            return '0';
        }

        return $formatted;
    }
}
