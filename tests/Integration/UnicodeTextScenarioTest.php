<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Integration;

use InvalidArgumentException;
use LibreSign\XObjectTemplate\Dto\CompileResult;
use LibreSign\XObjectTemplate\Dto\CompileRequest;
use LibreSign\XObjectTemplate\Layout\LayoutLine;
use LibreSign\XObjectTemplate\Layout\LayoutResult;
use LibreSign\XObjectTemplate\Pdf\SinglePagePdfExporter;
use LibreSign\XObjectTemplate\Pdf\SignerPhpCoreXObjectResourceBuilder;
use LibreSign\XObjectTemplate\Pdf\StandardFontMetrics;
use LibreSign\XObjectTemplate\Pdf\HarfBuzzComplexTextShaper;
use LibreSign\XObjectTemplate\Pdf\TrueTypeFont;
use LibreSign\XObjectTemplate\Pdf\TemplateDocumentBuilder;
use LibreSign\XObjectTemplate\XObjectTemplateCompiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SignerPHP\PdfCore\AppearanceXObject;
use SignerPHP\PdfCore\PdfDocument;
use SignerPHP\PdfCore\SignatureAppearance;
use SignerPHP\PdfCore\PdfValue\PDFValueReference;
use SignerPHP\PdfCore\PdfValue\PDFValueSimple;
use SignerPHP\PdfCore\Service\IncrementalUpdateWriter;
use SignerPHP\PdfCore\Service\PdfDocumentPreparer;
use Symfony\Component\Process\Process;

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
            html: '<div>{{ signer }}</div>',
            context: ['signer' => $text],
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

        $this->assertPreviewAndAppearance($result, $pdf, $text);
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
        yield 'Polish extended Latin' => ['text' => 'Łukasz Żółć', 'fontPath' => $dejavu];
        yield 'Turkish extended Latin' => ['text' => 'İpek Şahin', 'fontPath' => $dejavu];
        yield 'Ukrainian Cyrillic' => ['text' => 'Іван Петренко', 'fontPath' => $dejavu];
        yield 'supplementary Unicode scalar' => ['text' => '𐌀', 'fontPath' => $dejavu];
        yield 'Simplified Chinese' => ['text' => '简体中文', 'fontPath' => $droid];
        yield 'Traditional Chinese' => ['text' => '繁體中文', 'fontPath' => $droid];
        yield 'Japanese with kana' => ['text' => '山田 たろう', 'fontPath' => $droid];
        yield 'Japanese CJK' => ['text' => '山田太郎', 'fontPath' => $droid];
        yield 'escaped punctuation' => ['text' => 'Assinado (João) \\ Teste', 'fontPath' => $dejavu];
    }

    public function testIssue33SixLanguageExamplesShareOneValidFontResourceDocument(): void
    {
        $texts = ['Signé par Renée', 'Αλέξανδρος', 'Иван Иванов', 'محمد', 'דוד', '山田太郎'];
        $html = implode('', array_map(
            static fn (string $line): string =>
                '<div>' . htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>',
            $texts,
        ));
        $compiler = new XObjectTemplateCompiler(
            documentBuilder: (new TemplateDocumentBuilder())->withUnicodeFontFiles([
                '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
                '/usr/share/fonts/truetype/droid/DroidSansFallbackFull.ttf',
            ]),
        );
        $result = $compiler->compile(new CompileRequest(html: $html, width: 600, height: 400));

        self::assertSame(6, $result->metadata['line_count']);
        self::assertArrayHasKey('U1', $result->resources['Font']);
        self::assertArrayHasKey('U2', $result->resources['Font']);
        $pdf = (new SinglePagePdfExporter())->export($result);
        self::assertSame(2, substr_count($pdf, '/Subtype /CIDFontType2'));
        self::assertSame(2, substr_count($pdf, '/ToUnicode'));

        $this->assertPreviewAndAppearance($result, $pdf, implode("\n", $texts));
    }

    #[DataProvider('mixedFontProvider')]
    public function testMixedScriptLineUsesAllRequiredFontResources(string $text): void
    {
        $paths = [
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/droid/DroidSansFallbackFull.ttf',
        ];
        $fonts = array_map(static fn (string $path): TrueTypeFont => new TrueTypeFont($path), $paths);
        $metrics = new StandardFontMetrics($fonts);
        self::assertGreaterThan(0.0, $metrics->measureString('F1', 12.0, $text));
        $compiler = new XObjectTemplateCompiler(
            documentBuilder: (new TemplateDocumentBuilder())->withUnicodeFontFiles($paths),
        );
        $result = $compiler->compile(new CompileRequest(
            html: '<div>{{ signer }}</div>',
            context: ['signer' => $text],
            width: 500,
            height: 90,
        ));
        self::assertSame(1, $result->metadata['line_count']);
        self::assertArrayHasKey('U1', $result->resources['Font']);
        self::assertArrayHasKey('U2', $result->resources['Font']);
        self::assertStringContainsString('/U1 ', $result->contentStream);
        self::assertStringContainsString('/U2 ', $result->contentStream);
        $pdf = (new SinglePagePdfExporter())->export($result);
        self::assertSame(2, substr_count($pdf, '/Subtype /CIDFontType2'));
        $this->assertPreviewAndAppearance($result, $pdf, $text);
    }

    /** @return iterable<string, array{text: string}> */
    public static function mixedFontProvider(): iterable
    {
        yield 'Latin and Japanese' => ['text' => 'Renée 山田太郎'];
        yield 'adjacent Latin and Japanese' => ['text' => 'Renée山田太郎'];
        yield 'LTR with Arabic and Japanese' => ['text' => 'Signed by محمد / 山田太郎'];
        yield 'RTL with Japanese' => ['text' => 'محمد 山田太郎'];
        yield 'Hebrew and Japanese' => ['text' => 'דוד 山田太郎'];
        yield 'Greek and Simplified Chinese' => ['text' => 'Αλέξανδρος 简体中文'];
        yield 'Cyrillic and Traditional Chinese' => ['text' => 'Иван Иванов 繁體中文'];
        yield 'Latin to CJK and back to Latin' => ['text' => 'Renée 山田太郎 João'];
        yield 'combining accent and Japanese' => ['text' => "e\u{0301} 山田太郎"];
    }

    #[DataProvider('missingCoverageProvider')]
    public function testUnsupportedUnicodeFailsInsteadOfReplacingCharacters(string $text, array $fonts): void
    {
        $builder = (new TemplateDocumentBuilder())->withUnicodeFontFiles($fonts);
        $compiler = new XObjectTemplateCompiler(documentBuilder: $builder);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unicode text requires an embedded TrueType font');
        $compiler->compile(new CompileRequest(html: '<div>{{ name }}</div>', context: ['name' => $text]));
    }

    /** @return iterable<string, array{text: string, fonts: list<string>}> */
    public static function missingCoverageProvider(): iterable
    {
        $dejavu = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
        $droid = '/usr/share/fonts/truetype/droid/DroidSansFallbackFull.ttf';
        yield 'no configured font' => ['text' => 'Signé', 'fonts' => []];
        yield 'missing Japanese glyphs' => ['text' => '山田太郎', 'fonts' => [$dejavu]];
        yield 'missing Arabic glyphs' => ['text' => 'محمد', 'fonts' => [$droid]];
        yield 'missing Hebrew glyphs' => ['text' => 'דוד', 'fonts' => [$droid]];
        yield 'partial coverage in a mixed line' => ['text' => 'Renée 山田太郎', 'fonts' => [$dejavu]];
        yield 'missing supplementary scalar' => ['text' => "\u{10FFFF}", 'fonts' => [$dejavu, $droid]];
    }

    #[DataProvider('unicodeInputProvider')]
    public function testUnicodeTemplateInputPreservesDecodedText(string $html, array $context, string $expected): void
    {
        $compiler = new XObjectTemplateCompiler(
            documentBuilder: (new TemplateDocumentBuilder())->withUnicodeFontFiles([
                '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
                '/usr/share/fonts/truetype/droid/DroidSansFallbackFull.ttf',
            ]),
        );
        $result = $compiler->compile(new CompileRequest(html: $html, width: 450, height: 90, context: $context));
        $this->assertPreviewAndAppearance($result, (new SinglePagePdfExporter())->export($result), $expected);
    }

    /** @return iterable<string, array{html: string, context: array<string, scalar>, expected: string}> */
    public static function unicodeInputProvider(): iterable
    {
        yield 'named HTML entities' => ['<div>Sign&eacute; par Ren&eacute;e</div>', [], 'Signé par Renée'];
        yield 'decimal entities' => ['<div>&#23665;&#30000;&#22826;&#37070;</div>', [], '山田太郎'];
        yield 'hexadecimal entities' => ['<div>&#x645;&#x62D;&#x645;&#x62F;</div>', [], 'محمد'];
        yield 'supplementary-plane entity' => ['<div>&#x10300;</div>', [], '𐌀'];
        yield 'escaped context text and PDF punctuation' => [
            '<div>{{ signer }}</div>', ['signer' => 'João <Renée> & (山田)'],
            'João <Renée> & (山田)',
        ];
    }

    #[DataProvider('complexScriptWidthProvider')]
    public function testComplexScriptWidthsUseShapedGlyphAdvances(string $text): void
    {
        $font = new TrueTypeFont('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf');
        $shaped = (new HarfBuzzComplexTextShaper())->shape($text, $font);
        $expected = 0;
        foreach ($shaped as $glyph) {
            $expected += $glyph['advance'];
        }
        self::assertNotSame(0, $expected);
        self::assertEqualsWithDelta(
            $expected * 12.0 / 1000,
            (new StandardFontMetrics([$font]))->measureString('F1', 12.0, $text),
            0.000001,
        );
    }

    /** @return iterable<string, array{text: string}> */
    public static function complexScriptWidthProvider(): iterable
    {
        yield 'Arabic with diacritics' => ['text' => 'مُحَمَّد'];
        yield 'Hebrew with vowel marks' => ['text' => 'שָׁלוֹם'];
        yield 'decomposed accent' => ['text' => "e\u{0301}"];
    }

    #[DataProvider('complexScriptProvider')]
    public function testComplexScriptsRenderAndExtractOriginalUnicode(string $text): void
    {
        $font = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
        self::assertFileExists($font);
        $compiler = new XObjectTemplateCompiler(
            documentBuilder: (new TemplateDocumentBuilder())->withUnicodeFontFiles([$font]),
        );
        $result = $compiler->compile(new CompileRequest(
            html: '<div>{{ signer }}</div>',
            context: ['signer' => $text],
            width: 500,
            height: 90,
        ));
        $actualTextHex = 'FEFF';
        foreach (\LibreSign\XObjectTemplate\Pdf\TrueTypeFont::codepoints($text) as $scalar) {
            $actualTextHex .= \LibreSign\XObjectTemplate\Pdf\TrueTypeFont::utf16Hex($scalar);
        }
        self::assertStringContainsString('/ActualText <' . $actualTextHex . '>', $result->contentStream);
        self::assertStringContainsString('/U1 ', $result->contentStream);

        $pdf = (new SinglePagePdfExporter())->export($result);
        self::assertStringContainsString('/Subtype /CIDFontType2', $pdf);
        $this->assertPreviewAndAppearance($result, $pdf, $text);
    }

    /** @return iterable<string, array{text: string}> */
    public static function complexScriptProvider(): iterable
    {
        yield 'Hebrew (issue #33)' => ['text' => 'דוד'];
        yield 'Hebrew' => ['text' => 'שלום'];
        yield 'Persian with space' => ['text' => 'علی رضا'];
        yield 'Arabic with punctuation and digits' => ['text' => 'د. أحمد 123'];
        yield 'Arabic' => ['text' => 'محمد'];
        yield 'Arabic lam-alef ligature' => ['text' => 'لا'];
        yield 'Arabic diacritics' => ['text' => 'مُحَمَّد'];
        yield 'Arabic and Latin mixed' => ['text' => 'Signed by محمد 123'];
        yield 'Hebrew vowel marks' => ['text' => 'שָׁלוֹם'];
        yield 'Hebrew and Latin mixed' => ['text' => 'Signed by שלום 123'];
        yield 'combining accent' => ['text' => "e\u{0301}"];
    }

    #[DataProvider('shapingReferenceProvider')]
    public function testPdfGlyphIdsAndPositionsMatchNativeShaping(string $text, string $direction): void
    {
        $fontPath = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
        $font = new TrueTypeFont($fontPath);
        $process = new Process([
            'hb-shape', '--no-glyph-names', '--output-format=json', '--font-size=1000',
            '--direction=' . $direction, '--text=' . $text, $fontPath,
        ], null, ['LC_ALL' => 'C.UTF-8'], null, 20.0);
        $process->mustRun();
        $reference = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertNotEmpty($reference);
        $layout = new LayoutResult([new LayoutLine($text, 15.0, 40.0, 20.0, 'F1', '#000000')], []);
        $result = (new TemplateDocumentBuilder())->withUnicodeFontFiles([$fontPath])->build(
            new CompileRequest(html: '', width: 500, height: 90),
            $layout,
            hrtime(true),
        );
        $document = new PdfDocument();
        $resources = (new SignerPhpCoreXObjectResourceBuilder())->materialize($document, $result->resources);
        $type0 = $document->getObject($resources['Font']['U1']->asObjectReferenceOrNull());
        self::assertNotNull($type0);
        $descendant = $document->getObject($type0->getField('DescendantFonts')->val()[0]->asObjectReferenceOrNull());
        self::assertNotNull($descendant);
        $cidMap = $document->getObject($descendant->getField('CIDToGIDMap')->asObjectReferenceOrNull());
        self::assertNotNull($cidMap);
        $glyphIds = unpack('n*', $cidMap->getStream(false));
        self::assertIsArray($glyphIds);

        // The PDF stores glyph positions in logical text order, while the
        // native reference is in visual order. Compare actual GIDs and
        // absolute positions, not just ActualText or the CMap text.
        $expected = [];
        $advance = 0;
        foreach ($reference as $glyph) {
            $expected[] = [
                $glyph['g'],
                sprintf('%.6F', 15.0 + ($advance + $glyph['dx']) * 0.02),
                sprintf('%.6F', 40.0 + $glyph['dy'] * 0.02),
            ];
            $advance += $glyph['ax'];
        }
        preg_match_all(
            '/1 0 0 1 (-?\d+\.\d+) (-?\d+\.\d+) Tm <([0-9A-F]{4})> Tj/',
            $result->contentStream,
            $matches,
            PREG_SET_ORDER,
        );
        $actual = [];
        foreach ($matches as [, $x, $y, $cid]) {
            $actual[] = [$glyphIds[hexdec($cid) + 1], $x, $y];
        }
        sort($actual);
        sort($expected);
        self::assertSame($expected, $actual);
        self::assertEqualsWithDelta(
            $advance * 0.02,
            (new StandardFontMetrics([$font]))->measureString('F1', 20.0, $text),
            0.000001,
        );
    }

    /** @return iterable<string, array{text: string, direction: string}> */
    public static function shapingReferenceProvider(): iterable
    {
        yield 'Arabic joined letters' => ['محمد', 'rtl'];
        yield 'Arabic ligature' => ['لا', 'rtl'];
        yield 'Arabic stacked marks' => ['مُحَمَّد', 'rtl'];
        yield 'Persian' => ['علی', 'rtl'];
        yield 'Hebrew issue example' => ['דוד', 'rtl'];
        yield 'Hebrew vowel marks' => ['שָׁלוֹם', 'rtl'];
        yield 'Latin combining acute' => ["e\u{0301}", 'ltr'];
        yield 'Latin multiple combining marks' => ["a\u{0308}\u{0301}", 'ltr'];
        yield 'Greek combining accent' => ["α\u{0301}", 'ltr'];
        yield 'Cyrillic combining breve' => ["и\u{0306}", 'ltr'];
    }

    private function assertPreviewAndAppearance(CompileResult $result, string $preview, string $expected): void
    {
        $appearance = $this->createAppearancePdf($result);
        [$previewText, $previewRaster] = $this->readPdfEvidence($preview);
        [$appearanceText, $appearanceRaster] = $this->readPdfEvidence($appearance);
        foreach ([$previewText, $appearanceText] as $extracted) {
            self::assertSame(
                $expected,
                implode("\n", array_map(
                    static fn (string $line): string => self::normalizePopplerVisualBidi(trim($line)),
                    explode("\n", trim($extracted, " \t\n\r\0\x0B\f")),
                )),
                'The actual PDF must preserve the logical Unicode text, not just contain a CMap.',
            );
        }
        $parts = explode("\n", $previewRaster, 3);
        self::assertSame('P4', $parts[0]);
        self::assertCount(3, $parts);
        self::assertNotSame('', trim($parts[2], "\0"), 'The rendered page must contain visible ink.');
        self::assertSame(
            hash('sha256', $previewRaster),
            hash('sha256', $appearanceRaster),
            'The actual destination appearance must render exactly like the standalone XObject.',
        );
    }

    private function createAppearancePdf(CompileResult $result): string
    {
        $blank = (new SinglePagePdfExporter())->export(new CompileResult('', [], $result->bbox));
        $document = (new PdfDocumentPreparer())->prepare($blank);
        $page = $document->getPageInfo()->getPage(0);
        self::assertNotNull($page);
        $annotation = $document->createObject([
            'Type' => '/Annot',
            'Subtype' => '/Widget',
            'FT' => '/Sig',
            'F' => 132,
            'P' => new PDFValueReference($page->getOid()),
        ]);
        // Materialize through the public callback before attaching the resources.
        // This exercises pdf-core v0.1.2 too; the automatic callback constructor
        // integration requires the separately released pdf-core resource-builder API.
        $builder = new SignerPhpCoreXObjectResourceBuilder();
        $resources = ($builder->resourcesBuilder())($document, $result->resources);
        SignatureAppearance::new()
            ->withRect($result->bbox)
            ->withPdfDocument($document)
            ->withAnnotationObject($annotation)
            ->withPageRotate(new PDFValueSimple(0))
            ->withXObject(new AppearanceXObject($result->contentStream, $resources))
            ->generate();
        $page['Annots'] = [new PDFValueReference($annotation->getOid())];
        $document->addObject($page);
        $pdf = (new IncrementalUpdateWriter())->write($document)->raw();
        self::assertTrue(str_starts_with($pdf, $blank), 'The previous PDF revision must remain unchanged.');
        // PdfDocument and PageInfo retain references to one another. Release
        // their cycles before the next data set allocates several embedded fonts.
        unset($document);
        gc_collect_cycles();
        return $pdf;
    }

    /** @return array{string, string} */
    private function readPdfEvidence(string $pdf): array
    {
        $path = tempnam(sys_get_temp_dir(), 'unicode-appearance-');
        self::assertNotFalse($path);
        try {
            self::assertSame(strlen($pdf), file_put_contents($path, $pdf));
            return [
                $this->runPdfTool(['pdftotext', '-raw', $path, '-']),
                $this->runPdfTool(['pdftoppm', '-mono', '-r', '144', '-singlefile', $path]),
            ];
        } finally {
            unlink($path);
        }
    }

    /** @param list<string> $command */
    private function runPdfTool(array $command): string
    {
        $process = new Process($command, null, ['LC_ALL' => 'C.UTF-8'], null, 20.0);
        $process->mustRun();
        return $process->getOutput();
    }

    private static function normalizePopplerVisualBidi(string $text): string
    {
        // Poppler's pdftotext -raw emits RTL runs in visual order with RLE/PDF
        // controls even for semantic PDF ActualText. Normalize only its
        // presentation convention; the PDF itself retains logical Unicode.
        $normalized = preg_replace_callback(
            '/\x{202B}(.*?)\x{202C}/u',
            static function (array $match): string {
                $scalars = preg_split('//u', $match[1], -1, PREG_SPLIT_NO_EMPTY);
                return $scalars === false ? '' : implode('', array_reverse($scalars));
            },
            $text,
        );
        return $normalized ?? $text;
    }

    public function testAsciiRetainsLegacyFontStreamAndResources(): void
    {
        $result = (new XObjectTemplateCompiler())->compile(new CompileRequest(html: '<div>ASCII test</div>'));
        self::assertStringContainsString('(ASCII test) Tj', $result->contentStream);
        self::assertSame('/Helvetica', $result->resources['Font']['F1']['BaseFont']);
        self::assertArrayNotHasKey('U1', $result->resources['Font']);
    }
}
