<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit;

use LibreSign\XObjectTemplate\Exception\UnsupportedSubsetException;
use LibreSign\XObjectTemplate\Html\SubsetHtmlParser;
use PHPUnit\Framework\TestCase;

final class SubsetHtmlParserTest extends TestCase
{
    public function testUnsupportedTagThrowsException(): void
    {
        $parser = new SubsetHtmlParser();

        $this->expectException(UnsupportedSubsetException::class);
        $this->expectExceptionMessage('Tag <table> is not supported.');
        $parser->parse('<table><tr><td>x</td></tr></table>');
    }

    public function testParseNormalizesAttributesAndTrimsTextNodes(): void
    {
        $parser = new SubsetHtmlParser();

        $nodes = $parser->parse('<span STYLE=" color:#fff ">   Hello   </span>');

        self::assertCount(1, $nodes);
        self::assertSame('span', $nodes[0]->tag);
        self::assertSame('color:#fff', $nodes[0]->attributes['style']);
        self::assertSame('Hello', $nodes[0]->children[0]->text);
    }
}
