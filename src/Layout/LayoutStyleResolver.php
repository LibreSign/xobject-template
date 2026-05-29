<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Layout;

use LibreSign\XObjectTemplate\Css\StyleMap;

final readonly class LayoutStyleResolver
{
    public function styleValue(StyleMap $style, string $property, string $default): string
    {
        return $style->get($property, $default) ?? $default;
    }

    public function toPoints(string $value): float
    {
        $normalized = strtolower($value);
        $number = $this->extractNumericValue($normalized);
        if (str_ends_with($normalized, 'px')) {
            return $number * 0.75;
        }

        return $number;
    }

    public function resolveRelativeDimension(string $value, float $reference): float
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return 0.0;
        }

        if (str_ends_with($normalized, '%')) {
            $number = $this->extractNumericValue($normalized);

            return $reference * ($number / 100.0);
        }

        return $this->toPoints($normalized);
    }

    public function resolveLineHeight(StyleMap $style, float $fontSize): float
    {
        $defaultLineHeight = $fontSize * 1.2;
        $configuredLineHeight = $this->styleValue($style, 'line-height', '');

        if ($configuredLineHeight === '') {
            return $defaultLineHeight;
        }

        return max($defaultLineHeight, $this->toPoints($configuredLineHeight));
    }

    /**
     * @return array{top: float, right: float, bottom: float, left: float}
     */
    public function parseBoxSpacing(string $value): array
    {
        preg_match_all('/\S+/', $value, $matches);
        $tokens = $matches[0];

        if ($tokens === []) {
            return ['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0];
        }

        $points = array_map(fn (string $token): float => $this->toPoints($token), $tokens);
        $count = count($points);

        return match ($count) {
            1 => ['top' => $points[0], 'right' => $points[0], 'bottom' => $points[0], 'left' => $points[0]],
            2 => ['top' => $points[0], 'right' => $points[1], 'bottom' => $points[0], 'left' => $points[1]],
            3 => ['top' => $points[0], 'right' => $points[1], 'bottom' => $points[2], 'left' => $points[1]],
            default => ['top' => $points[0], 'right' => $points[1], 'bottom' => $points[2], 'left' => $points[3]],
        };
    }

    /**
     * @return array{top: float, right: float, bottom: float, left: float}
     */
    public function parseBoxSpacingRelative(string $value, float $widthReference, float $heightReference): array
    {
        preg_match_all('/\S+/', $value, $matches);
        $tokens = $matches[0];

        if ($tokens === []) {
            return ['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0];
        }

        [$top, $right, $bottom, $left] = $this->expandSpacingTokens($tokens);

        return [
            'top' => $this->resolveRelativeDimension($top, $heightReference),
            'right' => $this->resolveRelativeDimension($right, $widthReference),
            'bottom' => $this->resolveRelativeDimension($bottom, $heightReference),
            'left' => $this->resolveRelativeDimension($left, $widthReference),
        ];
    }

    public function resolveFontAlias(string $fontFamily, string $fontWeight): string
    {
        $primary = strtolower(explode(',', $fontFamily)[0]);
        $isBold = $this->isBoldWeight($fontWeight);

        if (str_contains($primary, 'times')) {
            return $isBold ? 'F4' : 'F3';
        }

        if (str_contains($primary, 'courier')) {
            return $isBold ? 'F6' : 'F5';
        }

        return $isBold ? 'F2' : 'F1';
    }

    public function isAbsolutelyPositioned(StyleMap $style): bool
    {
        return strtolower($this->styleValue($style, 'position', '')) === 'absolute';
    }

    /**
     * @param list<string> $tokens
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function expandSpacingTokens(array $tokens): array
    {
        return match (count($tokens)) {
            1 => [$tokens[0], $tokens[0], $tokens[0], $tokens[0]],
            2 => [$tokens[0], $tokens[1], $tokens[0], $tokens[1]],
            3 => [$tokens[0], $tokens[1], $tokens[2], $tokens[1]],
            default => [$tokens[0], $tokens[1], $tokens[2], $tokens[3]],
        };
    }

    private function isBoldWeight(string $fontWeight): bool
    {
        $normalized = strtolower($fontWeight);
        if ($normalized === 'bold' || $normalized === 'bolder') {
            return true;
        }

        if (is_numeric($normalized)) {
            return $normalized >= 600;
        }

        return false;
    }

    private function extractNumericValue(string $value): float
    {
        return (float) preg_replace('/[^0-9.\-]/', '', $value);
    }
}
