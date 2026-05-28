<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf;

final class ColorParser
{
    public function toPdfRgb(string $hexColor): string
    {
        return $this->toPdfColor($hexColor, 'rg');
    }

    public function toPdfStrokeRgb(string $hexColor): string
    {
        return $this->toPdfColor($hexColor, 'RG');
    }

    private function toPdfColor(string $hexColor, string $operator): string
    {
        $hex = ltrim(trim($hexColor), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            return sprintf('0 0 0 %s', $operator);
        }

        $channels = str_split($hex, 2);
        $redChannel = round(hexdec($channels[0]) / 255, 4);
        $greenChannel = round(hexdec($channels[1]) / 255, 4);
        $blueChannel = round(hexdec($channels[2]) / 255, 4);

        return sprintf('%s %s %s %s', $redChannel, $greenChannel, $blueChannel, $operator);
    }
}
