<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit;

use LibreSign\XObjectTemplate\Dto\CompileRequest;
use LibreSign\XObjectTemplate\Html\SubsetHtmlParser;
use LibreSign\XObjectTemplate\Layout\LinearLayoutEngine;
use LibreSign\XObjectTemplate\Pdf\ColorParser;
use LibreSign\XObjectTemplate\Pdf\PdfEscaper;
use LibreSign\XObjectTemplate\Pdf\TemplateDocumentBuilder;
use LibreSign\XObjectTemplate\XObjectTemplateCompiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class XObjectTemplateCompilerTest extends TestCase
{
    #[DataProvider('htmlProvider')]
    public function testCompileSubsetHtmlGeneratesExpectedOperators(string $html, string $expectedSnippet): void
    {
        $compiler = new XObjectTemplateCompiler();

        $result = $compiler->compile(new CompileRequest(html: $html));

        self::assertStringContainsString($expectedSnippet, $result->contentStream);
        self::assertSame([0.0, 0.0, 240.0, 84.0], $result->bbox);
        self::assertArrayHasKey('Font', $result->resources);
        self::assertArrayHasKey('F1', $result->resources['Font']);
    }

    /**
     * @return iterable<string, array{html: string, expectedSnippet: string}>
     */
    public static function htmlProvider(): iterable
    {
        yield 'paragraph text' => [
            'html' => '<p style="font-size:10;color:#000">Rendered for Alice</p>',
            'expectedSnippet' => '(Rendered for Alice) Tj',
        ];

        yield 'line break text' => [
            'html' => '<div>Approved</div><br><span>At 2026-05-27</span>',
            'expectedSnippet' => '(At 2026-05-27) Tj',
        ];

        yield 'image command' => [
            'html' => '<img src="/tmp/example-image.png" style="width:24px;height:24px" />',
            'expectedSnippet' => '/Im0 Do',
        ];

        yield 'bold font weight mapping' => [
            'html' => '<p style="font-size:10;font-weight:bold">Bold Name</p>',
            'expectedSnippet' => '/F2 10.000000 Tf',
        ];

        yield 'font family mapping times' => [
            'html' => '<p style="font-size:10;font-family:Times New Roman">Times Text</p>',
            'expectedSnippet' => '/F3 10.000000 Tf',
        ];

        yield 'margin and padding affect position' => [
            'html' => '<p style="font-size:10;margin:8;padding:4">Offset Text</p>',
            'expectedSnippet' => '20.000000 48.000000 Td',
        ];
    }

    public function testCompileUsesProvidedTemplateDocumentBuilderInstance(): void
    {
        $builder = (new TemplateDocumentBuilder())->withFontResources([
            'Z1' => [
                'Type' => '/Font',
                'Subtype' => '/Type1',
                'BaseFont' => '/Helvetica',
            ],
        ]);
        $compiler = new XObjectTemplateCompiler(null, null, null, null, $builder);

        $result = $compiler->compile(new CompileRequest(html: '<p>Hello</p>'));

        self::assertArrayHasKey('Z1', $result->resources['Font']);
        self::assertArrayNotHasKey('F1', $result->resources['Font']);
    }

    public function testCompilerConstructorAcceptsExplicitPdfDependencies(): void
    {
        $pdfEscaper = new PdfEscaper();
        $colorParser = new ColorParser();
        $compiler = new XObjectTemplateCompiler(
            null,
            null,
            $pdfEscaper,
            $colorParser,
        );

        $result = $compiler->compile(new CompileRequest(html: '<p>Hello</p>'));

        $builderProp = new ReflectionProperty($compiler, 'documentBuilder');
        $builder = $builderProp->getValue($compiler);
        $escProp = new ReflectionProperty($builder, 'pdfEscaper');
        $colProp = new ReflectionProperty($builder, 'colorParser');

        self::assertStringContainsString('(Hello) Tj', $result->contentStream);
        self::assertSame([0.0, 0.0, 240.0, 84.0], $result->bbox);
        self::assertArrayHasKey('Font', $result->resources);
        self::assertSame($pdfEscaper, $escProp->getValue($builder));
        self::assertSame($colorParser, $colProp->getValue($builder));
    }

    private function getBuilderProperty(object $obj, string $name): object
    {
        $prop = new ReflectionProperty($obj, $name);
        return $prop->getValue($obj);
    }

    public function testCompilerConstructorKeepsProvidedParserAndLayoutInstances(): void
    {
        $htmlParser = new SubsetHtmlParser();
        $layoutEngine = new LinearLayoutEngine();
        $compiler = new XObjectTemplateCompiler($htmlParser, $layoutEngine);

        $htmlParserProperty = new ReflectionProperty($compiler, 'htmlParser');
        $layoutEngineProperty = new ReflectionProperty($compiler, 'layoutEngine');

        self::assertSame($htmlParser, $htmlParserProperty->getValue($compiler));
        self::assertSame($layoutEngine, $layoutEngineProperty->getValue($compiler));
    }
}
