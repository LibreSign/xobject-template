<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Html;

use DOMDocument;
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
        self::assertSame(
            'font-size:10; margin:2;font-weight:bold',
            $nodes[0]->children[0]->children[0]->attributes['style'],
        );
        self::assertSame('br', $nodes[0]->children[1]->tag);
        self::assertSame('World', $nodes[0]->children[2]->text);
        self::assertSame('font-size:10; margin:2', $nodes[0]->children[2]->attributes['style']);
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
