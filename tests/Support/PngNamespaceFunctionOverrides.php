<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf\Png;

use LibreSign\XObjectTemplate\Tests\Support\PngNamespaceFunctionOverrides;

function unpack(string $format, string $data, int $offset = 0): array|false
{
    return PngNamespaceFunctionOverrides::callUnpack($format, $data, $offset);
}

function gzcompress(string $data, int $level = -1, int $encoding = \ZLIB_ENCODING_DEFLATE): string|false
{
    return PngNamespaceFunctionOverrides::callGzcompress($data, $level, $encoding);
}

namespace LibreSign\XObjectTemplate\Tests\Support;

use Closure;

final class PngNamespaceFunctionOverrides
{
    private static ?Closure $unpackOverride = null;
    private static ?Closure $gzcompressOverride = null;

    public static function reset(): void
    {
        self::$unpackOverride = null;
        self::$gzcompressOverride = null;
    }

    public static function overrideUnpack(?callable $override): void
    {
        self::$unpackOverride = $override !== null ? Closure::fromCallable($override) : null;
    }

    public static function overrideGzcompress(?callable $override): void
    {
        self::$gzcompressOverride = $override !== null ? Closure::fromCallable($override) : null;
    }

    public static function callUnpack(string $format, string $data, int $offset = 0): array|false
    {
        if (self::$unpackOverride !== null) {
            return (self::$unpackOverride)($format, $data, $offset);
        }

        return \unpack($format, $data, $offset);
    }

    public static function callGzcompress(
        string $data,
        int $level = -1,
        int $encoding = \ZLIB_ENCODING_DEFLATE,
    ): string|false {
        if (self::$gzcompressOverride !== null) {
            return (self::$gzcompressOverride)($data, $level, $encoding);
        }

        return \gzcompress($data, $level, $encoding);
    }
}
