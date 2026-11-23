<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf;

use InvalidArgumentException;

/** @internal UTF-8 scalar decoding and UTF-16BE CMap conversion. */
final readonly class UnicodeScalarCodec
{
    /** @return list<int> */
    public function decode(string $value): array
    {
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) {
            throw new InvalidArgumentException('Text must be valid UTF-8.');
        }
        $codepoints = [];
        foreach ($characters as $character) {
            $codepoints[] = $this->decodeCharacter($character);
        }
        return $codepoints;
    }

    private function decodeCharacter(string $character): int
    {
        $first = ord($character[0]);
        return match (strlen($character)) {
            1 => $first,
            2 => (($first & 31) << 6) | (ord($character[1]) & 63),
            3 => (($first & 15) << 12) | ((ord($character[1]) & 63) << 6) | (ord($character[2]) & 63),
            4 => (($first & 7) << 18) | ((ord($character[1]) & 63) << 12)
                | ((ord($character[2]) & 63) << 6) | (ord($character[3]) & 63),
        };
    }

    public function encodeUtf16Hex(int $codepoint): string
    {
        if ($codepoint < 0 || $codepoint > 0x10FFFF || ($codepoint >= 0xD800 && $codepoint <= 0xDFFF)) {
            throw new InvalidArgumentException('Invalid Unicode scalar.');
        }
        if ($codepoint <= 0xFFFF) {
            return sprintf('%04X', $codepoint);
        }
        $scalar = $codepoint - 0x10000;
        return sprintf('%04X%04X', 0xD800 | ($scalar >> 10), 0xDC00 | ($scalar & 1023));
    }
}
