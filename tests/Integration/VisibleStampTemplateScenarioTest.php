<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Integration;

use LibreSign\XObjectTemplate\Dto\CompileResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VisibleStampTemplateScenarioTest extends TestCase
{
    #[DataProvider('visibleStampLayoutProvider')]
    public function testPhaseOneVisibleStampLayoutsCanBeCompiledAndExported(
        string $slug,
        string $layout,
        int $expectedImageCount,
        array $expectedTexts,
    ): void {
        $factory = new VisibleStampPreviewFactory(dirname(__DIR__, 2));
        ['result' => $result, 'pdf' => $pdf, 'previewPath' => $previewPath] = $factory->compilePhaseOneLayoutPreview(
            $slug,
            $layout,
            dirname(__DIR__, 2) . '/build/visible-stamp-previews',
        );

        $this->assertBasePreviewExport(
            $result,
            $pdf,
            $previewPath,
            $expectedImageCount,
            VisibleStampPreviewFactory::DEFAULT_PREVIEW_WIDTH,
            VisibleStampPreviewFactory::DEFAULT_PREVIEW_HEIGHT,
        );

        foreach ($expectedTexts as $expectedText) {
            self::assertStringContainsString($expectedText, $result->contentStream);
            self::assertStringContainsString($expectedText, $pdf);
        }
    }

    public function testGovBrLikeVisibleStampCanBeCompiledAndExportedUsingSupportedHtmlAndCssOnly(): void
    {
        $scenario = self::govBrLikeScenario();
        $factory = new VisibleStampPreviewFactory(dirname(__DIR__, 2));

        [
            'result' => $result,
            'pdf' => $pdf,
            'previewPath' => $previewPath,
            'logoPath' => $logoPath,
        ] = $factory->compileGovBrLikePreview(
            $scenario['slug'],
            $scenario['width'],
            $scenario['height'],
            dirname(__DIR__, 2) . '/build/visible-stamp-previews',
        );

        $this->assertBasePreviewExport(
            $result,
            $pdf,
            $previewPath,
            $scenario['expectedImageCount'],
        );
        self::assertSame($logoPath, $result->resources['XObject']['Im0']['Source']);

        foreach ($scenario['expectedTexts'] as $expectedText) {
            self::assertStringContainsString($expectedText, $result->contentStream);
            self::assertStringContainsString($expectedText, $pdf);
        }

        self::assertStringContainsString('1 1 1 rg', $result->contentStream);
        self::assertStringContainsString('RG', $result->contentStream);
    }

    /**
     * @return iterable<string, array{
     *     slug: string,
     *     layout: string,
     *     expectedImageCount: int,
     *     expectedTexts: list<string>
     * }>
     */
    public static function visibleStampLayoutProvider(): iterable
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

    private function assertBasePreviewExport(
        CompileResult $result,
        string $pdf,
        string $previewPath,
        int $expectedImageCount,
        ?float $expectedWidth = null,
        ?float $expectedHeight = null,
    ): void {
        self::assertSame($expectedImageCount, count($result->resources['XObject'] ?? []));

        if ($expectedWidth !== null && $expectedHeight !== null) {
            self::assertSame($expectedWidth, $result->resources['XObject']['Im0']['Width']);
            self::assertSame($expectedHeight, $result->resources['XObject']['Im0']['Height']);
            self::assertStringContainsString(
                sprintf(
                    'q %F 0 0 %F %F %F cm /Im0 Do Q',
                    $expectedWidth,
                    $expectedHeight,
                    0.0,
                    0.0,
                ),
                $result->contentStream,
            );
        }

        self::assertStringStartsWith("%PDF-1.4\n", $pdf);
        self::assertStringContainsString('/Subtype /Form', $pdf);
        self::assertStringContainsString('/Im0 Do', $result->contentStream);
        self::assertFileExists($previewPath);
        self::assertSame($pdf, file_get_contents($previewPath));

        if ($expectedImageCount > 1) {
            self::assertStringContainsString('/Im1 Do', $result->contentStream);
        }
    }
}
