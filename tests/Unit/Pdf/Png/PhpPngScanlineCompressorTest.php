<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf\Png;

use LibreSign\XObjectTemplate\Pdf\Png\PhpPngScanlineCompressor;
use PHPUnit\Framework\TestCase;

final class PhpPngScanlineCompressorTest extends TestCase
{
    public function testCompressReturnsRoundTrippableCompressedScanlines(): void
    {
        $compressor = new PhpPngScanlineCompressor();
        $scanlines = "\x00\xff\x00\x00\x00\x00\xff\x00";

        $compressed = $compressor->compress($scanlines);

        self::assertIsString($compressed);
        self::assertSame($scanlines, gzuncompress($compressed));
    }
}
