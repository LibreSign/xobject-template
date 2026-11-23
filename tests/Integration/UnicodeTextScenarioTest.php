<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Integration;

use InvalidArgumentException;
use LibreSign\XObjectTemplate\Dto\CompileRequest;
use LibreSign\XObjectTemplate\Pdf\SinglePagePdfExporter;
use LibreSign\XObjectTemplate\Pdf\TemplateDocumentBuilder;
use LibreSign\XObjectTemplate\XObjectTemplateCompiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UnicodeTextScenarioTest extends TestCase
{
    #[DataProvider('unicodeScriptProvider')]
    public function testEmbeddedUnicodeFontsRoundTripThroughPdfResources(
        string $text,
        string $fontPath,
    ): void {
        self::assertFileExists($fontPath, 'Unicode regression fonts must be installed on the test runner.');
        $builder = (new TemplateDocumentBuilder())->withUnicodeFontFiles([$fontPath]);
        $compiler = new XObjectTemplateCompiler(documentBuilder: $builder);

        $result = $compiler->compile(new CompileRequest(
            html: '<div>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>',
            width: 450,
            height: 84,
        ));

        self::assertStringContainsString('/U1 ', $result->contentStream);
        self::assertMatchesRegularExpression('/<[0-9A-F]+> Tj/', $result->contentStream);
        self::assertArrayHasKey('U1', $result->resources['Font']);

        $pdf = (new SinglePagePdfExporter())->export($result);

        self::assertStringContainsString('/Subtype /Type0', $pdf);
        self::assertStringContainsString('/Subtype /CIDFontType2', $pdf);
        self::assertStringContainsString('/Encoding /Identity-H', $pdf);
        self::assertStringContainsString('/CIDToGIDMap', $pdf);
        self::assertStringContainsString('/FontFile2', $pdf);
        self::assertStringContainsString('/ToUnicode', $pdf);
        foreach (\LibreSign\XObjectTemplate\Pdf\TrueTypeFont::codepoints($text) as $codepoint) {
            self::assertStringContainsString(
                \LibreSign\XObjectTemplate\Pdf\TrueTypeFont::utf16Hex($codepoint),
                $pdf,
                sprintf('Unicode scalar U+%04X must survive in ToUnicode.', $codepoint),
            );
        }

        self::assertNotSame(
            '',
            trim((string) shell_exec('command -v pdftotext 2>/dev/null')),
            'Poppler pdftotext is required for extraction tests.'
        );

        $file = tempnam(sys_get_temp_dir(), 'libresign-unicode-');
        self::assertNotFalse($file);
        try {
            file_put_contents($file, $pdf);
            $output = shell_exec('pdftotext -layout ' . escapeshellarg($file) . ' - 2>/dev/null');
            self::assertNotNull($output, 'Poppler pdftotext must be installed.');
            $actual = trim(str_replace(["\u{202A}", "\u{202B}", "\u{202C}"], '', $output), " \t\n\r\0\x0B\f");
            self::assertSame($text, $actual);
        } finally {
            unlink($file);
        }
    }

    /** @return iterable<string, array{text: string, fontPath: string}> */
    public static function unicodeScriptProvider(): iterable
    {
        $dejavu = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
        $droid = '/usr/share/fonts/truetype/droid/DroidSansFallbackFull.ttf';
        yield 'Portuguese accents' => ['text' => 'João Gonçalves', 'fontPath' => $dejavu];
        yield 'French accents' => ['text' => 'Signé par Renée', 'fontPath' => $dejavu];
        yield 'Greek' => ['text' => 'Αλέξανδρος', 'fontPath' => $dejavu];
        yield 'Cyrillic' => ['text' => 'Иван Иванов', 'fontPath' => $dejavu];
        yield 'Japanese CJK' => ['text' => '山田太郎', 'fontPath' => $droid];
        yield 'escaped punctuation' => ['text' => 'Assinado (João) \\ Teste', 'fontPath' => $dejavu];
    }

    public function testInvalidUnconfiguredUnicodeCannotSilentlyCorruptPdf(): void
    {
        $compiler = new XObjectTemplateCompiler();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unicode text requires an embedded TrueType font');
        $compiler->compile(new CompileRequest(html: '<div>Signé</div>'));
    }

    public function testUnicodeFontWithoutRequiredGlyphIsRejected(): void
    {
        $font = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
        self::assertFileExists($font);
        $compiler = new XObjectTemplateCompiler(
            documentBuilder: (new TemplateDocumentBuilder())->withUnicodeFontFiles([$font]),
        );
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unicode text requires an embedded TrueType font');
        $compiler->compile(new CompileRequest(html: '<div>山田太郎</div>'));
    }

    #[DataProvider('unsupportedComplexScriptProvider')]
    public function testComplexScriptsRequireExternalShapingEngine(string $text): void
    {
        $compiler = new XObjectTemplateCompiler(
            documentBuilder: (new TemplateDocumentBuilder())->withUnicodeFontFiles([
                '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            ]),
        );
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Complex-script text requires');
        $compiler->compile(new CompileRequest(html: '<div>' . $text . '</div>'));
    }

    /** @return iterable<string, array{text: string}> */
    public static function unsupportedComplexScriptProvider(): iterable
    {
        yield 'Hebrew' => ['text' => 'שלום'];
        yield 'Arabic' => ['text' => 'محمد'];
        yield 'combining accent' => ['text' => "e\u{0301}"];
    }

    public function testAsciiRetainsLegacyFontStreamAndResources(): void
    {
        $result = (new XObjectTemplateCompiler())->compile(new CompileRequest(html: '<div>ASCII test</div>'));
        self::assertStringContainsString('(ASCII test) Tj', $result->contentStream);
        self::assertSame('/Helvetica', $result->resources['Font']['F1']['BaseFont']);
        self::assertArrayNotHasKey('U1', $result->resources['Font']);
    }
}
