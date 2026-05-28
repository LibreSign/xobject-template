<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Css;

use LibreSign\XObjectTemplate\Css\InlineStyleParser;
use PHPUnit\Framework\Attributes\DataProvider;
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
        self::assertNull($map->get(''));
    }

    #[DataProvider('invalidChunkProvider')]
    public function testParseSkipsEachInvalidChunkVariantAndContinuesParsing(
        string $style,
        array $expectedPresent,
        array $expectedAbsent,
    ): void {
        $parser = new InlineStyleParser();

        $map = $parser->parse($style);

        foreach ($expectedPresent as $name => $value) {
            self::assertSame($value, $map->get($name));
        }

        foreach ($expectedAbsent as $name) {
            self::assertNull($map->get($name));
        }
    }

    /**
     * @return iterable<string, array{
     *     style: string,
     *     expectedPresent: array<string, string>,
     *     expectedAbsent: list<string>
     * }>
     */
    public static function invalidChunkProvider(): iterable
    {
        yield 'empty name does not stop later declarations' => [
            'style' => ':red; color:blue; padding:1',
            'expectedPresent' => ['color' => 'blue', 'padding' => '1'],
            'expectedAbsent' => [''],
        ];

        yield 'empty value does not stop later declarations' => [
            'style' => 'font-size:; color:green; margin:2',
            'expectedPresent' => ['color' => 'green', 'margin' => '2'],
            'expectedAbsent' => ['font-size'],
        ];
    }
}
