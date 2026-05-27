<?php

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit;

use LibreSign\XObjectTemplate\Dto\CompileRequest;
use LibreSign\XObjectTemplate\XObjectTemplateCompiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
            'html' => '<p style="font-size:10;color:#000">Signed by Alice</p>',
            'expectedSnippet' => '(Signed by Alice) Tj',
        ];

        yield 'line break text' => [
            'html' => '<div>Approved</div><br><span>At 2026-05-27</span>',
            'expectedSnippet' => '(At 2026-05-27) Tj',
        ];

        yield 'image command' => [
            'html' => '<img src="/tmp/signature.png" style="width:24px;height:24px" />',
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
}
