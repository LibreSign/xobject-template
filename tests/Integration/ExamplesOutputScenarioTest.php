<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Integration;

use LibreSign\XObjectTemplate\Dto\CompileRequest;
use LibreSign\XObjectTemplate\Pdf\SinglePagePdfExporter;
use LibreSign\XObjectTemplate\Tests\Support\PngFixtureFactory;
use LibreSign\XObjectTemplate\XObjectTemplateCompiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExamplesOutputScenarioTest extends TestCase
{
    #[DataProvider('exampleProvider')]
    public function testExampleScriptsExecuteAndGenerateExpectedArtifacts(string $exampleFile): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $examplePath = $projectRoot . '/docs/source/examples/' . $exampleFile;
        $examplesOutputRoot = $projectRoot . '/build/examples';

        self::assertFileExists($examplePath);

        $result = require $examplePath;

        self::assertIsArray($result);
        self::assertArrayHasKey('generated_files', $result);
        self::assertArrayHasKey('content_stream', $result);
        self::assertArrayHasKey('resources', $result);
        self::assertArrayHasKey('bbox', $result);

        self::assertIsString($result['content_stream']);
        self::assertNotSame('', trim((string) $result['content_stream']));

        self::assertIsArray($result['resources']);
        self::assertArrayHasKey('Font', $result['resources']);

        self::assertIsArray($result['bbox']);
        self::assertCount(4, $result['bbox']);

        /** @var list<string> $generatedFiles */
        $generatedFiles = $result['generated_files'];
        self::assertNotSame([], $generatedFiles);

        foreach ($generatedFiles as $generatedFile) {
            self::assertStringStartsWith($examplesOutputRoot . '/', $generatedFile);
            self::assertFileExists($generatedFile);
            $contents = file_get_contents($generatedFile);
            self::assertIsString($contents);
            self::assertNotSame('', $contents);

            if (str_ends_with($generatedFile, '.pdf')) {
                self::assertStringStartsWith('%PDF-', $contents);
                self::assertStringContainsString('%%EOF', $contents);
            }
        }

        if (isset($result['pdf_file']) && is_string($result['pdf_file'])) {
            self::assertStringStartsWith($examplesOutputRoot . '/', $result['pdf_file']);
            $pdfContents = file_get_contents($result['pdf_file']);
            self::assertIsString($pdfContents);
            self::assertStringStartsWith('%PDF-', $pdfContents);
        }
    }

    public function testEveryPhpExampleFileIsCoveredByTheProvider(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $allPhpExamples = glob($projectRoot . '/docs/source/examples/*.php');
        self::assertIsArray($allPhpExamples);

        $actual = array_map(static fn (string $path): string => basename($path), $allPhpExamples);
        sort($actual);

        $expected = self::documentedExamples();
        sort($expected);

        self::assertSame($expected, $actual);
    }

    /**
     * @return list<string>
     */
    private static function documentedExamples(): array
    {
        return [
            'basic-template.php',
            'preview-pdf.php',
            'images.php',
            'svg.php',
            'placement.php',
            'interpolation.php',
        ];
    }

    /**
     * @return iterable<string, array{exampleFile: string}>
     */
    public static function exampleProvider(): iterable
    {
        foreach (self::documentedExamples() as $exampleFile) {
            yield $exampleFile => ['exampleFile' => $exampleFile];
        }
    }

    #[DataProvider('integrationRenderingScenarioProvider')]
    public function testIntegrationRenderingScenariosAlsoGenerateExampleArtifacts(
        string $slug,
        string $html,
        float $width,
        float $height,
        int $maxStreamLength,
    ): void {
        $projectRoot = dirname(__DIR__, 2);
        $scenarioOutputRoot = $projectRoot . '/build/examples/integration-scenarios';

        if (!is_dir($scenarioOutputRoot)) {
            mkdir($scenarioOutputRoot, 0777, true);
        }

        $resolvedHtml = $this->resolveIntegrationScenarioHtmlAssets($html, $scenarioOutputRoot);

        $compiler = new XObjectTemplateCompiler();
        $result = $compiler->compile(new CompileRequest(
            html: $resolvedHtml,
            width: $width,
            height: $height,
        ));

        self::assertStringContainsString('BT', $result->contentStream);
        self::assertStringContainsString('ET', $result->contentStream);
        self::assertLessThan($maxStreamLength, strlen($result->contentStream));

        $pdf = (new SinglePagePdfExporter())->export($result);
        $pdfFile = $scenarioOutputRoot . '/' . $slug . '.pdf';
        file_put_contents($pdfFile, $pdf);

        $jsonFile = $scenarioOutputRoot . '/' . $slug . '.json';
        file_put_contents($jsonFile, json_encode([
            'slug' => $slug,
            'bbox' => $result->bbox,
            'resources' => $result->resources,
            'content_stream_length' => strlen($result->contentStream),
            'metadata' => $result->metadata,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        self::assertFileExists($pdfFile);
        self::assertFileExists($jsonFile);

        $pdfContents = file_get_contents($pdfFile);
        self::assertIsString($pdfContents);
        self::assertStringStartsWith('%PDF-', $pdfContents);
        self::assertStringContainsString('%%EOF', $pdfContents);

        $jsonContents = file_get_contents($jsonFile);
        self::assertIsString($jsonContents);
        self::assertStringContainsString('"content_stream_length"', $jsonContents);
        self::assertStringContainsString('"resources"', $jsonContents);
    }

    private function resolveIntegrationScenarioHtmlAssets(string $html, string $scenarioOutputRoot): string
    {
        if (!str_contains($html, '/fixture/example-image.png')) {
            return $html;
        }

        $assetRoot = $scenarioOutputRoot . '/assets';
        if (!is_dir($assetRoot)) {
            mkdir($assetRoot, 0777, true);
        }

        $imagePath = $assetRoot . '/example-image.png';
        if (!is_file($imagePath)) {
            $contents = PngFixtureFactory::createRgbaPngFromPixelRenderer(
                20,
                20,
                static fn (): array => [48, 98, 188, 255],
            );
            file_put_contents($imagePath, $contents);
        }

        return str_replace('/fixture/example-image.png', $imagePath, $html);
    }

    /**
     * @return iterable<string, array{slug: string, html: string, width: float, height: float, maxStreamLength: int}>
     */
    public static function integrationRenderingScenarioProvider(): iterable
    {
        yield 'title and status block' => [
            'slug' => 'title-status-block',
            'html' => '<div style="font-size:10">Prepared for Demo User</div>'
                . '<p style="font-size:9">Document approved</p>',
            'width' => 260.0,
            'height' => 90.0,
            'maxStreamLength' => 1200,
        ];

        yield 'image with reference text' => [
            'slug' => 'image-reference-text',
            'html' => '<img src="/fixture/example-image.png" style="width:20px;height:20px" />'
                . '<span style="font-size:9">ID 42</span>',
            'width' => 260.0,
            'height' => 90.0,
            'maxStreamLength' => 1400,
        ];

        yield 'styled block with alignment and spacing' => [
            'slug' => 'styled-aligned-spacing',
            'html' => '<div style="font-family:Times New Roman;font-weight:700;text-align:right;'
                . 'margin:6;padding:2;width:220">Prepared by Styled User</div>',
            'width' => 260.0,
            'height' => 90.0,
            'maxStreamLength' => 1800,
        ];
    }

    #[DataProvider('visibleStampExampleScenarioProvider')]
    public function testVisibleStampScenariosAlsoGenerateExampleArtifacts(
        string $kind,
        string $slug,
        ?string $layout,
        float $width,
        float $height,
        int $expectedImageCount,
        array $expectedTexts,
    ): void {
        $projectRoot = dirname(__DIR__, 2);
        $outputRoot = $projectRoot . '/build/examples/visible-stamp';
        $factory = new VisibleStampPreviewFactory($projectRoot);

        if ($kind === 'phase_one') {
            if (!is_string($layout)) {
                throw new \InvalidArgumentException('Phase-one visible stamp scenario requires a layout identifier.');
            }

            ['result' => $result, 'pdf' => $pdf, 'previewPath' => $previewPath] = $factory->compilePhaseOneLayoutPreview(
                $slug,
                $layout,
                $outputRoot,
            );

            self::assertSame($width, $result->resources['XObject']['Im0']['Width']);
            self::assertSame($height, $result->resources['XObject']['Im0']['Height']);
        } else {
            [
                'result' => $result,
                'pdf' => $pdf,
                'previewPath' => $previewPath,
                'logoPath' => $logoPath,
            ] = $factory->compileGovBrLikePreview(
                $slug,
                $width,
                $height,
                $outputRoot,
            );

            self::assertSame($logoPath, $result->resources['XObject']['Im0']['Source']);
        }

        self::assertSame($expectedImageCount, count($result->resources['XObject'] ?? []));
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringContainsString('/Subtype /Form', $pdf);
        self::assertStringContainsString('/Im0 Do', $result->contentStream);
        self::assertFileExists($previewPath);
        self::assertSame($pdf, file_get_contents($previewPath));
        self::assertStringStartsWith($outputRoot . '/', $previewPath);

        foreach ($expectedTexts as $expectedText) {
            self::assertStringContainsString($expectedText, $result->contentStream);
            self::assertStringContainsString($expectedText, $pdf);
        }
    }

    /**
     * @return iterable<string, array{
     *     kind: 'phase_one'|'govbr',
     *     slug: string,
     *     layout: string|null,
     *     width: float,
     *     height: float,
     *     expectedImageCount: int,
     *     expectedTexts: list<string>
     * }>
     */
    public static function visibleStampExampleScenarioProvider(): iterable
    {
        foreach (self::visibleStampPhaseOneLayouts() as $name => $scenario) {
            yield 'phase one: ' . $name => [
                'kind' => 'phase_one',
                'slug' => $scenario['slug'],
                'layout' => $scenario['layout'],
                'width' => VisibleStampPreviewFactory::DEFAULT_PREVIEW_WIDTH,
                'height' => VisibleStampPreviewFactory::DEFAULT_PREVIEW_HEIGHT,
                'expectedImageCount' => $scenario['expectedImageCount'],
                'expectedTexts' => $scenario['expectedTexts'],
            ];
        }

        $govBr = self::govBrLikeScenario();
        yield 'govbr-like' => [
            'kind' => 'govbr',
            'slug' => $govBr['slug'],
            'layout' => null,
            'width' => $govBr['width'],
            'height' => $govBr['height'],
            'expectedImageCount' => $govBr['expectedImageCount'],
            'expectedTexts' => $govBr['expectedTexts'],
        ];
    }

    /**
     * @return iterable<string, array{
     *     slug: string,
     *     layout: string,
     *     expectedImageCount: int,
     *     expectedTexts: list<string>
     * }>
     */
    private static function visibleStampPhaseOneLayouts(): iterable
    {
        yield 'signature and metadata at right' => [
            'slug' => 'signature-and-metadata-right',
            'layout' => 'signature_and_metadata_right',
            'expectedImageCount' => 2,
            'expectedTexts' => [
                'Signed with LibreSign',
                'admin',
                'Issuer: Preview Issuer',
                'Date: 2026-05-28T16:40:21+00:00',
            ],
        ];

        yield 'label and metadata at right' => [
            'slug' => 'label-and-metadata-right',
            'layout' => 'label_and_metadata_right',
            'expectedImageCount' => 1,
            'expectedTexts' => [
                'admin',
                'Signed with LibreSign',
                'Issuer: Preview Issuer',
                'Date: 2026-05-28T16:40:21+00:00',
            ],
        ];

        yield 'signature centered' => [
            'slug' => 'signature-centered',
            'layout' => 'signature_centered',
            'expectedImageCount' => 2,
            'expectedTexts' => [],
        ];

        yield 'metadata only at top left' => [
            'slug' => 'metadata-only-top-left',
            'layout' => 'metadata_only_top_left',
            'expectedImageCount' => 1,
            'expectedTexts' => [
                'Signed with LibreSign',
                'admin',
                'Issuer: Preview Issuer',
                'Date: 2026-05-28T16:40:21+00:00',
            ],
        ];

        yield 'two columns with centered cells' => [
            'slug' => 'two-columns-centered-cells',
            'layout' => 'two_columns_centered_cells',
            'expectedImageCount' => 2,
            'expectedTexts' => [
                'Signed with LibreSign',
                'Preview Issuer',
                'Date:',
                '2026-05-28T16:40:21+00:00',
            ],
        ];
    }

    /**
     * @return array{
     *     slug: string,
     *     width: float,
     *     height: float,
     *     expectedImageCount: int,
     *     expectedTexts: list<string>
     * }
     */
    private static function govBrLikeScenario(): array
    {
        return [
            'slug' => 'govbr-like-visible-stamp',
            'width' => 760.0,
            'height' => 210.0,
            'expectedImageCount' => 1,
            'expectedTexts' => [
                'Documento assinado digitalmente',
                'ASSINANTE DE EXEMPLO',
                'Data: 01/01/2026 12:00:00-0300',
                'Verifique em https://verificador.iti.br',
            ],
        ];
    }
}