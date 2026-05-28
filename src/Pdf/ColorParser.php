<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf;

final class ColorParser
{
    public function toPdfRgb(string $hexColor): string
    {
        $hex = ltrim(trim($hexColor), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            return '0 0 0 rg';
        }

        $channels = str_split($hex, 2);
        $r = round(hexdec($channels[0]) / 255, 4);
        $g = round(hexdec($channels[1]) / 255, 4);
        $b = round(hexdec($channels[2]) / 255, 4);

        return sprintf('%s %s %s rg', $r, $g, $b);
    }
}
