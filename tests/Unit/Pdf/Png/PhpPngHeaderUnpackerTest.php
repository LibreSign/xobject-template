<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf\Png;

use LibreSign\XObjectTemplate\Pdf\Png\PhpPngHeaderUnpacker;
use PHPUnit\Framework\TestCase;

final class PhpPngHeaderUnpackerTest extends TestCase
{
    public function testUnpackReturnsStructuredHeaderFields(): void
    {
        $unpacker = new PhpPngHeaderUnpacker();

        self::assertSame(
            [
                'width' => 3,
                'height' => 2,
                'bitDepth' => 8,
                'colorType' => 6,
                'compression' => 0,
                'filter' => 0,
                'interlace' => 0,
            ],
            $unpacker->unpack(pack('NNCCCCC', 3, 2, 8, 6, 0, 0, 0)),
        );
    }

    public function testUnpackReturnsFalseWhenHeaderBytesAreIncomplete(): void
    {
        $unpacker = new PhpPngHeaderUnpacker();

        self::assertFalse($unpacker->unpack("\x00\x00\x00"));
    }
}
