<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Support;

use InvalidArgumentException;

final class PngFixtureFactory
{
    public static function createPng(
        int $width,
        int $height,
        int $colorType,
        string $scanlines,
        int $bitDepth = 8,
        int $interlace = 0,
    ): string {
        $ihdr = pack('NNCCCCC', $width, $height, $bitDepth, $colorType, 0, 0, $interlace);
        $idat = gzcompress($scanlines);
        if ($idat === false) {
            throw new InvalidArgumentException('Failed to compress PNG scanlines.');
        }

        return "\x89PNG\r\n\x1a\n"
            . self::createChunk('IHDR', $ihdr)
            . self::createChunk('IDAT', $idat)
            . self::createChunk('IEND', '');
    }

    /**
     * @param callable(int, int, int, int): array{0: int|float, 1: int|float, 2: int|float, 3: int|float} $pixelRenderer
     */
    public static function createRgbaPngFromPixelRenderer(
        int $width,
        int $height,
        callable $pixelRenderer,
    ): string {
        $scanlines = '';
        for ($y = 0; $y < $height; ++$y) {
            $row = "\x00";
            for ($x = 0; $x < $width; ++$x) {
                [$red, $green, $blue, $alpha] = $pixelRenderer($x, $y, $width, $height);
                $row .= self::packByte($red)
                    . self::packByte($green)
                    . self::packByte($blue)
                    . self::packByte($alpha);
            }

            $scanlines .= $row;
        }

        return self::createPng($width, $height, 6, $scanlines);
    }

    private static function createChunk(string $type, string $data): string
    {
        $crc = crc32($type . $data);
        if ($crc < 0) {
            $crc += 4_294_967_296;
        }

        return pack('N', strlen($data))
            . $type
            . $data
            . pack('N', $crc);
    }

    private static function packByte(int|float $value): string
    {
        $clamped = max(0, min(255, (int) round($value)));

        return chr($clamped);
    }
}
