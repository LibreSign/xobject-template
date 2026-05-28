<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Css;

use LibreSign\XObjectTemplate\Css\InlineStyleParser;
use PHPUnit\Framework\TestCase;

final class InlineStyleParserTest extends TestCase
{
    public function testParseNormalizesNamesAndKeepsLaterValidChunksAfterMalformedOnes(): void
    {
        $parser = new InlineStyleParser();

        $map = $parser->parse('broken; COLOR : #fff ; bad:value:extra ; font-weight : 700 ; :missing-name; empty:');

        self::assertSame('#fff', $map->get('color'));
        self::assertSame('700', $map->get('font-weight'));
        self::assertNull($map->get('COLOR'));
    }

    public function testParseUsesSingleSplitForValuesContainingColons(): void
    {
        $parser = new InlineStyleParser();

        $map = $parser->parse('background:url(http://example.test/a:b.png);font-size:10');

        self::assertSame('url(http://example.test/a:b.png)', $map->get('background'));
        self::assertSame('10', $map->get('font-size'));
    }

    public function testParseSkipsEmptyNameOrValueWithoutStoppingTheLoop(): void
    {
        $parser = new InlineStyleParser();

        $map = $parser->parse(':bad;color:red;font-size:;padding:4');

        self::assertSame('red', $map->get('color'));
        self::assertSame('4', $map->get('padding'));
        self::assertNull($map->get('font-size'));
    }
}
