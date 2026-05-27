<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit;

use LibreSign\XObjectTemplate\Html\SubsetHtmlParser;
use PHPUnit\Framework\TestCase;

final class SubsetHtmlParserAdvancedTest extends TestCase
{
    public function testParseMergesInheritedStylesAndKeepsAllowedTags(): void
    {
        $parser = new SubsetHtmlParser();
        $nodes = $parser->parse(
            '<div style="font-size:10; margin:2">'
            . '<span style="font-weight:bold">Hello</span>'
            . '<br />'
            . 'World'
            . '</div>',
        );

        self::assertCount(1, $nodes);
        self::assertSame('div', $nodes[0]->tag);
        self::assertSame('font-size:10; margin:2', $nodes[0]->attributes['style']);
        self::assertCount(3, $nodes[0]->children);
        self::assertSame('span', $nodes[0]->children[0]->tag);
        self::assertSame('Hello', $nodes[0]->children[0]->children[0]->text);
        self::assertSame('font-size:10; margin:2;font-weight:bold', $nodes[0]->children[0]->children[0]->attributes['style']);
        self::assertSame('br', $nodes[0]->children[1]->tag);
        self::assertSame('World', $nodes[0]->children[2]->text);
        self::assertSame('font-size:10; margin:2', $nodes[0]->children[2]->attributes['style']);
    }
}
