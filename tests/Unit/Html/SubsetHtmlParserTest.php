<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Html;

use DOMDocument;
use LibreSign\XObjectTemplate\Exception\UnsupportedSubsetException;
use LibreSign\XObjectTemplate\Html\SubsetHtmlParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SubsetHtmlParserTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function unsupportedTagProvider(): iterable
    {
        yield 'table is outside the supported subset' => [
            '<table><tr><td>x</td></tr></table>',
            'Tag <table> is not supported.',
        ];

        yield 'unordered lists are outside the supported subset' => [
            '<ul><li>x</li></ul>',
            'Tag <ul> is not supported.',
        ];

        yield 'semantic strong tags are outside the supported subset' => [
            '<strong>x</strong>',
            'Tag <strong> is not supported.',
        ];
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: string, 4: list<string>}>
     */
    public static function inheritableStyleProvider(): iterable
    {
        yield 'layout-only styles stay on parent while text styles inherit' => [
            '<div style="width:58%;height:100%;padding:18 24;font-size:20;color:#123456">'
            . '<div style="font-weight:700">Title</div>'
            . '</div>',
            'width:58%;height:100%;padding:18 24;font-size:20;color:#123456',
            'font-size:20;color:#123456;font-weight:700',
            'font-size:20;color:#123456;font-weight:700',
            ['width:58%', 'height:100%', 'padding:18 24'],
        ];

        yield 'text alignment white-space and color inherit together' => [
            '<div style="text-align:right;white-space:nowrap;color:#222222;font-size:11">'
            . '<span>Aligned</span>'
            . '</div>',
            'text-align:right;white-space:nowrap;color:#222222;font-size:11',
            'text-align:right;white-space:nowrap;color:#222222;font-size:11',
            'text-align:right;white-space:nowrap;color:#222222;font-size:11',
            [],
        ];

        yield 'malformed declarations preserve last inheritable values and colon values' => [
            '<div style=" ; COLOR : #fff ; broken ; font-family : Times:Bold ; invalid: ; '
            . 'white-space : nowrap ; hyphens : auto ; color : #abc ; line-height : 12 ; ">'
            . '<span style="font-weight:bold">Hello</span>'
            . '</div>',
            '; COLOR : #fff ; broken ; font-family : Times:Bold ; invalid: ; white-space : nowrap ; '
            . 'hyphens : auto ; color : #abc ; line-height : 12 ;',
            'color:#abc;font-family:Times:Bold;white-space:nowrap;hyphens:auto;line-height:12;font-weight:bold',
            'color:#abc;font-family:Times:Bold;white-space:nowrap;hyphens:auto;line-height:12;font-weight:bold',
            [],
        ];
    }

    #[DataProvider('unsupportedTagProvider')]
    public function testUnsupportedTagThrowsException(string $html, string $expectedMessage): void
    {
        $parser = new SubsetHtmlParser();

        $this->expectException(UnsupportedSubsetException::class);
        $this->expectExceptionMessage($expectedMessage);
        $parser->parse($html);
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
        self::assertSame('font-size:10;font-weight:bold', $nodes[0]->children[0]->attributes['style']);
        self::assertSame(
            'font-size:10;font-weight:bold',
            $nodes[0]->children[0]->children[0]->attributes['style'],
        );
        self::assertSame('br', $nodes[0]->children[1]->tag);
        self::assertSame('World', $nodes[0]->children[2]->text);
        self::assertSame('font-size:10; margin:2', $nodes[0]->children[2]->attributes['style']);
    }

    #[DataProvider('inheritableStyleProvider')]
    public function testParseOnlyInheritsTextualStylesToDescendants(
        string $html,
        string $expectedRootStyle,
        string $expectedChildStyle,
        string $expectedTextStyle,
        array $excludedFragments,
    ): void {
        $parser = new SubsetHtmlParser();

        $nodes = $parser->parse($html);

        self::assertSame($expectedRootStyle, $nodes[0]->attributes['style']);
        self::assertSame($expectedChildStyle, $nodes[0]->children[0]->attributes['style']);
        self::assertSame($expectedTextStyle, $nodes[0]->children[0]->children[0]->attributes['style']);

        foreach ($excludedFragments as $excludedFragment) {
            self::assertStringNotContainsString($excludedFragment, $nodes[0]->children[0]->attributes['style']);
        }
    }

    public function testParseNormalizesTagAndAttributeNamesAndKeepsAllAttributes(): void
    {
        $parser = new SubsetHtmlParser();

        $nodes = $parser->parse('<DIV STYLE="font-size:10" DATA-ID=" 42 ">Hi</DIV>');

        self::assertCount(1, $nodes);
        self::assertSame('div', $nodes[0]->tag);
        self::assertArrayHasKey('style', $nodes[0]->attributes);
        self::assertArrayHasKey('data-id', $nodes[0]->attributes);
        self::assertSame('font-size:10', $nodes[0]->attributes['style']);
        self::assertSame('42', $nodes[0]->attributes['data-id']);
    }

    public function testParseDoesNotKeepEmptyStyleAttributeWhenNoStyleIsResolved(): void
    {
        $parser = new SubsetHtmlParser();

        $nodes = $parser->parse('<div><span style="   ">Hello</span></div>');

        self::assertCount(1, $nodes);
        self::assertArrayNotHasKey('style', $nodes[0]->attributes);
        self::assertArrayNotHasKey('style', $nodes[0]->children[0]->attributes);
        self::assertArrayNotHasKey('style', $nodes[0]->children[0]->children[0]->attributes);
    }

    public function testParseTrimsInheritedStyleAndRestoresLibxmlInternalErrorFlag(): void
    {
        $parser = new SubsetHtmlParser();
        $previous = libxml_use_internal_errors(false);

        try {
            $nodes = $parser->parse('<div style=" font-size:10 "><span style=" font-weight:bold ">Hi</span></div>');
        } finally {
            $current = libxml_use_internal_errors(false);
            libxml_use_internal_errors($previous);
        }

        self::assertFalse($current);
        self::assertSame('font-size:10', $nodes[0]->attributes['style']);
        self::assertSame(
            'font-size:10;font-weight:bold',
            $nodes[0]->children[0]->children[0]->attributes['style'],
        );
    }

    public function testParseClearsLibxmlErrorsAfterMalformedHtmlAndRestoresPreviousFlag(): void
    {
        $parser = new SubsetHtmlParser();
        libxml_clear_errors();
        $previous = libxml_use_internal_errors(true);

        try {
            $nodes = $parser->parse('<div style="font-size:10"><span>Broken');
            $current = libxml_use_internal_errors(true);
            $errors = libxml_get_errors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        self::assertTrue($current);
        self::assertSame([], $errors);
        self::assertCount(1, $nodes);
        self::assertSame('div', $nodes[0]->tag);
        self::assertSame('Broken', $nodes[0]->children[0]->children[0]->text);
    }

    public function testParsePreservesUtf8CharactersFromHtmlFragment(): void
    {
        $parser = new SubsetHtmlParser();

        $nodes = $parser->parse('<span>ação € 😀</span>');

        self::assertCount(1, $nodes);
        self::assertSame('ação € 😀', $nodes[0]->children[0]->text);
    }

    public function testParseKeepsAllTopLevelNodesInOrder(): void
    {
        $parser = new SubsetHtmlParser();

        $nodes = $parser->parse('<span>First</span><span>Second</span>');

        $this->assertCount(2, $nodes);
        $this->assertSame('First', $nodes[0]->children[0]->text);
        $this->assertSame('Second', $nodes[1]->children[0]->text);
    }

    public function testParseClearsPreExistingLibxmlErrorBuffer(): void
    {
        $parser = new SubsetHtmlParser();
        libxml_clear_errors();
        $previous = libxml_use_internal_errors(true);

        try {
            $probe = new DOMDocument('1.0', 'UTF-8');
            $probe->loadXML('<root><broken></root>');
            $errorsBefore = libxml_get_errors();

            $parser->parse('<div>ok</div>');

            $errorsAfter = libxml_get_errors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        self::assertNotSame([], $errorsBefore);
        self::assertSame([], $errorsAfter);
    }
}
