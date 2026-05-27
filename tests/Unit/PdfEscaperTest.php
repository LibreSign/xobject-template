<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit;

use LibreSign\XObjectTemplate\Pdf\PdfEscaper;
use PHPUnit\Framework\TestCase;

final class PdfEscaperTest extends TestCase
{
    public function testEscapeLiteralStringEscapesBackslashesAndParentheses(): void
    {
        $escaper = new PdfEscaper();

        self::assertSame('A \\(B\\) \\\\ C', $escaper->escapeLiteralString('A (B) \\ C'));
    }
}
