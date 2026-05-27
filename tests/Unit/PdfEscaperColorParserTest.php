<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit;

use LibreSign\XObjectTemplate\Pdf\ColorParser;
use LibreSign\XObjectTemplate\Pdf\PdfEscaper;
use PHPUnit\Framework\TestCase;

final class PdfEscaperColorParserTest extends TestCase
{
    public function testEscaperAndColorParserCanBeCombinedInPdfContent(): void
    {
        $escaper = new PdfEscaper();
        $colorParser = new ColorParser();

        $escaped = $escaper->escapeLiteralString('Signed (Demo) \\ user');
        $rgb = $colorParser->toPdfRgb('#000000');

        self::assertSame('Signed \\(Demo\\) \\\\ user', $escaped);
        self::assertSame('0 0 0 rg', $rgb);
    }
}
