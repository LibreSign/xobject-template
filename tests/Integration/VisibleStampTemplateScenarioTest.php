<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Integration;

use LibreSign\XObjectTemplate\Dto\CompileRequest;
use LibreSign\XObjectTemplate\Dto\CompileResult;
use LibreSign\XObjectTemplate\Pdf\SinglePagePdfExporter;
use LibreSign\XObjectTemplate\Tests\Support\PngFixtureFactory;
use LibreSign\XObjectTemplate\XObjectTemplateCompiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VisibleStampTemplateScenarioTest extends TestCase
{
    #[DataProvider('visibleStampLayoutScenarios')]
    public function testVisibleStampLayoutsCanBeCompiledAndExported(
        string $slug,
        string $layout,
        float $width,
        float $height,
        int $expectedImageCount,
        array $expectedTexts,
    ): void {
        $projectRoot = dirname(__DIR__, 2);
        $previewRoot = $projectRoot . '/build/examples/visible-stamp';
        $assetRoot = $previewRoot . '/assets';
        self::ensureDirectoryExists($previewRoot);
        self::ensureDirectoryExists($assetRoot);

        $backgroundPath = self::createBackgroundPreview(
            $assetRoot . '/background-' . $slug . '.png',
            (int) $width,
            (int) $height,
        );
        $signaturePath = self::layoutUsesSignatureImage($layout)
            ? self::createSignaturePreview($assetRoot . '/signature-' . $slug . '.png')
            : null;

        ['result' => $result, 'pdf' => $pdf, 'previewPath' => $previewPath] = self::compilePreviewWithSize(
            $slug,
            self::buildLayoutHtml($layout, $backgroundPath, $signaturePath),
            $width,
            $height,
            $previewRoot,
        );

        $this->assertBasePreviewExport(
            $result,
            $pdf,
            $previewPath,
            $expectedImageCount,
            $width,
            $height,
        );

        foreach ($expectedTexts as $expectedText) {
            self::assertStringContainsString($expectedText, $result->contentStream);
            self::assertStringContainsString($expectedText, $pdf);
        }
    }

    /**
     * @return iterable<string, array{
     *     slug: string,
     *     layout: string,
     *     width: float,
     *     height: float,
     *     expectedImageCount: int,
     *     expectedTexts: list<string>
     * }>
     */
    public static function visibleStampLayoutScenarios(): iterable
    {
        yield 'signature and metadata at right' => [
            'slug' => 'signature-and-metadata-right',
            'layout' => 'signature_and_metadata_right',
            'width' => 804.0,
            'height' => 230.0,
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
            'width' => 804.0,
            'height' => 230.0,
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
            'width' => 804.0,
            'height' => 230.0,
            'expectedImageCount' => 2,
            'expectedTexts' => [],
        ];

        yield 'metadata only at top left' => [
            'slug' => 'metadata-only-top-left',
            'layout' => 'metadata_only_top_left',
            'width' => 804.0,
            'height' => 230.0,
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
            'width' => 804.0,
            'height' => 230.0,
            'expectedImageCount' => 2,
            'expectedTexts' => [
                'Signed with LibreSign',
                'Preview Issuer',
                'Date:',
                '2026-05-28T16:40:21+00:00',
            ],
        ];
    }

    public function testGovBrLikeVisibleStampCanBeCompiledAndExportedUsingSupportedHtmlAndCssOnly(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $slug = 'govbr-like-visible-stamp';
        $expectedImageCount = 1;
        $expectedTexts = [
            'Documento assinado digitalmente',
            'ASSINANTE DE EXEMPLO',
            'Data: 01/01/2026 12:00:00-0300',
            'Verifique em https://verificador.iti.br',
        ];

        $width = 400.0;
        $height = 100.0;
        $logoPath = $projectRoot . '/tests/Fixtures/Pdf/Svg/govbr-logo.svg';
        if (!is_file($logoPath)) {
            throw new \InvalidArgumentException(sprintf('GovBR logo fixture was not found at "%s".', $logoPath));
        }

        $html = sprintf(
            <<<HTML
            <div style="width:100%%;height:100%%;background-color:#ffffff">
                <img src="%s" style="position:absolute;left:0px;top:36px;width:146px;height:52px" />
                <div style="position:absolute;left:156px;top:8px;width:244px;height:52px">
                    <div style="font-size:14px;line-height:16px;color:#1b1b1b;text-align:left">
                        Documento assinado digitalmente
                    </div>
                    <div style="font-size:14px;line-height:12px;font-weight:700;color:#111111;margin:8px 0 0 0">
                        ASSINANTE DE EXEMPLO
                    </div>
                    <div style="font-size:14px;line-height:9px;color:#222222;margin:4px 0 0 0">
                        Data: 01/01/2026 12:00:00-0300
                    </div>
                    <div style="font-size:14px;line-height:9px;color:#222222;margin:3px 0 0 0">
                        Verifique em https://verificador.iti.br
                    </div>
                </div>
            </div>
            HTML,
            htmlspecialchars($logoPath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );

        [
            'result' => $result,
            'pdf' => $pdf,
            'previewPath' => $previewPath,
        ] = self::compilePreviewWithSize(
            $slug,
            $html,
            $width,
            $height,
            $projectRoot . '/build/examples/visible-stamp',
        );

        $this->assertBasePreviewExport(
            $result,
            $pdf,
            $previewPath,
            $expectedImageCount,
        );
        self::assertSame($width, $result->bbox[2] - $result->bbox[0]);
        self::assertSame($height, $result->bbox[3] - $result->bbox[1]);
        self::assertSame($logoPath, $result->resources['XObject']['Im0']['Source']);

        foreach ($expectedTexts as $expectedText) {
            self::assertStringContainsString($expectedText, $result->contentStream);
            self::assertStringContainsString($expectedText, $pdf);
        }

        self::assertStringContainsString('1 1 1 rg', $result->contentStream);
        self::assertStringNotContainsString('RG', $result->contentStream);
    }

    /**
     * @return array{result: CompileResult, pdf: string, previewPath: string}
     */
    private static function compilePreviewWithSize(
        string $slug,
        string $html,
        float $width,
        float $height,
        string $previewRoot,
    ): array {
        self::ensureDirectoryExists($previewRoot);
        self::removeLegacyPreviewPngs($previewRoot, $slug);

        $compiler = new XObjectTemplateCompiler();
        $result = $compiler->compile(new CompileRequest(
            html: $html,
            width: $width,
            height: $height,
        ));

        $pdf = (new SinglePagePdfExporter())->export($result);
        $previewPath = $previewRoot . '/' . $slug . '.pdf';
        file_put_contents($previewPath, $pdf);

        return [
            'result' => $result,
            'pdf' => $pdf,
            'previewPath' => $previewPath,
        ];
    }

    private static function buildLayoutHtml(string $layout, string $backgroundPath, ?string $signaturePath): string
    {
        $background = sprintf(
            '<img src="%s" style="position:absolute;left:0;top:0;width:100%%;height:100%%" />',
            self::escapeAttribute($backgroundPath),
        );

        return match ($layout) {
            'signature_and_metadata_right' => sprintf(
                '<div style="display:flex;flex-direction:row;width:100%%;height:100%%;padding:14 18">%s'
                . '<div style="width:52%%;height:100%%;padding:18 10 0 0">'
                . '<img src="%s" style="width:360px;height:120px;margin:18 0 0 0" />'
                . '</div>'
                . '<div style="width:48%%;height:100%%;padding:4 0 0 0">'
                . '<div style="font-size:20;font-weight:700">Signed with LibreSign</div>'
                . '<div style="font-size:18;margin:6 0 0 0">admin</div>'
                . '<div style="font-size:14;margin:8 0 0 0">Issuer: Preview Issuer</div>'
                . '<div style="font-size:14;margin:4 0 0 0">Date: 2026-05-28T16:40:21+00:00</div>'
                . '</div>'
                . '</div>',
                $background,
                self::requireSignaturePath($signaturePath),
            ),
            'label_and_metadata_right' => sprintf(
                '<div style="display:flex;flex-direction:row;width:100%%;height:100%%;padding:14 18">%s'
                . '<div style="display:flex;justify-content:center;align-items:center;width:42%%;height:100%%">'
                . '<div style="font-size:46;font-weight:700">admin</div>'
                . '</div>'
                . '<div style="width:58%%;height:100%%;padding:10 0 0 0">'
                . '<div style="font-size:20;font-weight:700">Signed with LibreSign</div>'
                . '<div style="font-size:18;margin:6 0 0 0">Issuer: Preview Issuer</div>'
                . '<div style="font-size:18;margin:6 0 0 0">Date: 2026-05-28T16:40:21+00:00</div>'
                . '</div>'
                . '</div>',
                $background,
            ),
            'signature_centered' => sprintf(
                '<div style="display:flex;justify-content:center;align-items:center;width:100%%;height:100%%">%s'
                . '<img src="%s" style="width:600px;height:140px" />'
                . '</div>',
                $background,
                self::requireSignaturePath($signaturePath),
            ),
            'metadata_only_top_left' => sprintf(
                '<div style="width:100%%;height:100%%">%s'
                . '<div style="width:58%%;padding:18 24">'
                . '<div style="font-size:20;font-weight:700">Signed with LibreSign</div>'
                . '<div style="font-size:18;margin:6 0 0 0">admin</div>'
                . '<div style="font-size:16;margin:8 0 0 0">Issuer: Preview Issuer</div>'
                . '<div style="font-size:16;margin:6 0 0 0">Date: 2026-05-28T16:40:21+00:00</div>'
                . '</div>'
                . '</div>',
                $background,
            ),
            'two_columns_centered_cells' => sprintf(
                '<div style="display:flex;flex-direction:row;width:100%%;height:100%%;padding:14 18">%s'
                . '<div style="display:flex;justify-content:center;align-items:center;width:44%%;height:100%%">'
                . '<img src="%s" style="width:320px;height:100px" />'
                . '</div>'
                . '<div style="display:flex;justify-content:center;align-items:center;width:56%%;height:100%%">'
                . '<div style="width:300px;height:120px">'
                . '<div style="font-size:20;font-weight:700">Signed with LibreSign</div>'
                . '<div style="font-size:18;margin:8 0 0 0">Preview Issuer</div>'
                . '<div style="font-size:16;margin:10 0 0 0">Date: 2026-05-28T16:40:21+00:00</div>'
                . '</div>'
                . '</div>'
                . '</div>',
                $background,
                self::requireSignaturePath($signaturePath),
            ),
            default => throw new \InvalidArgumentException(sprintf('Unknown visible stamp layout "%s".', $layout)),
        };
    }

    private static function createBackgroundPreview(string $path, int $width, int $height): string
    {
        if (is_file($path)) {
            return $path;
        }

        $contents = PngFixtureFactory::createRgbaPngFromPixelRenderer(
            $width,
            $height,
            function (int $x, int $y, int $width, int $height): array {
                $background = [245, 247, 250, 255];
                $diagonal = abs(($height * $x) - ($width * $y));
                $inverseDiagonal = abs(($height * ($width - $x)) - ($width * $y));
                $bandStrength = ($diagonal < $width * 10 || $inverseDiagonal < $width * 10) ? 55 : 0;
                $ringCenterX = $width * 0.74;
                $ringCenterY = $height * 0.5;
                $distance = sqrt((($x - $ringCenterX) ** 2) + (($y - $ringCenterY) ** 2));
                $ringStrength = ($distance > $height * 0.18 && $distance < $height * 0.24) ? 35 : 0;
                $strength = max($bandStrength, $ringStrength);

                return [
                    max(0, $background[0] - $strength),
                    max(0, $background[1] - $strength),
                    max(0, $background[2] - $strength),
                    255,
                ];
            },
        );

        file_put_contents($path, $contents);

        return $path;
    }

    private static function createSignaturePreview(string $path): string
    {
        if (is_file($path)) {
            return $path;
        }

        $contents = PngFixtureFactory::createRgbaPngFromPixelRenderer(
            640,
            180,
            function (int $x, int $y, int $width, int $height): array {
                $normalizedX = $width === 1 ? 0.0 : $x / ($width - 1);
                $primaryWave = ($height * 0.56)
                    + sin($normalizedX * 7.1) * ($height * 0.13)
                    + sin($normalizedX * 14.2) * ($height * 0.035);
                $secondaryWave = ($height * 0.4)
                    + cos($normalizedX * 16.0) * ($height * 0.05);
                $underline = ($height * 0.78) + sin($normalizedX * 5.6) * ($height * 0.015);

                $alpha = 0;
                if (abs($y - $primaryWave) <= 2.2 && $normalizedX >= 0.08 && $normalizedX <= 0.92) {
                    $alpha = 255;
                }

                if (abs($y - $secondaryWave) <= 1.6 && $normalizedX >= 0.0 && $normalizedX <= 0.18) {
                    $alpha = max($alpha, 220);
                }

                if (abs($y - $underline) <= 1.0 && $normalizedX >= 0.16 && $normalizedX <= 0.9) {
                    $alpha = max($alpha, 185);
                }

                return [20, 28, 40, $alpha];
            },
        );

        file_put_contents($path, $contents);

        return $path;
    }

    private static function removeLegacyPreviewPngs(string $previewRoot, string $slug): void
    {
        $legacyCandidates = [
            $previewRoot . '/' . $slug . '.png',
            $previewRoot . '/' . $slug . '-1.png',
        ];

        foreach ($legacyCandidates as $legacyCandidate) {
            if (is_file($legacyCandidate)) {
                unlink($legacyCandidate);
            }
        }
    }

    private static function requireSignaturePath(?string $signaturePath): string
    {
        if ($signaturePath === null) {
            throw new \InvalidArgumentException('This visible stamp layout requires a signature image.');
        }

        return self::escapeAttribute($signaturePath);
    }

    private static function ensureDirectoryExists(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        mkdir($directory, 0777, true);
    }

    private static function layoutUsesSignatureImage(string $layout): bool
    {
        return in_array(
            $layout,
            ['signature_and_metadata_right', 'signature_centered', 'two_columns_centered_cells'],
            true,
        );
    }

    private static function escapeAttribute(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
