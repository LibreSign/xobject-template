<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Integration;

use LibreSign\XObjectTemplate\Dto\CompileRequest;
use LibreSign\XObjectTemplate\Pdf\SinglePagePdfExporter;
use LibreSign\XObjectTemplate\Tests\Support\PngFixtureFactory;
use LibreSign\XObjectTemplate\XObjectTemplateCompiler;

final class VisibleStampPreviewFactory
{
    public const DEFAULT_PREVIEW_WIDTH = 804.0;
    public const DEFAULT_PREVIEW_HEIGHT = 230.0;

    public function __construct(private readonly string $projectRoot)
    {
    }

    /**
     * @return array{result: \LibreSign\XObjectTemplate\Dto\CompileResult, pdf: string, previewPath: string}
     */
    public function compilePhaseOneLayoutPreview(string $slug, string $layout, string $previewRoot): array
    {
        $assetRoot = $previewRoot . '/assets';
        $this->ensureDirectoryExists($previewRoot);
        $this->ensureDirectoryExists($assetRoot);

        $backgroundPath = $this->createBackgroundPreview($assetRoot . '/background-' . $slug . '.png');
        $signaturePath = $this->layoutUsesSignatureImage($layout)
            ? $this->createSignaturePreview($assetRoot . '/signature-' . $slug . '.png')
            : null;

        return $this->compilePreviewWithSize(
            $slug,
            $this->buildLayoutHtml($layout, $backgroundPath, $signaturePath),
            self::DEFAULT_PREVIEW_WIDTH,
            self::DEFAULT_PREVIEW_HEIGHT,
            $previewRoot,
        );
    }

    /**
     * @return array{result: \LibreSign\XObjectTemplate\Dto\CompileResult, pdf: string, previewPath: string, logoPath: string}
     */
    public function compileGovBrLikePreview(
        string $slug,
        float $width,
        float $height,
        string $previewRoot,
    ): array {
        $logoPath = $this->projectRoot . '/tests/Fixtures/Pdf/Svg/govbr-logo.svg';
        if (!is_file($logoPath)) {
            throw new \InvalidArgumentException(sprintf('GovBR logo fixture was not found at "%s".', $logoPath));
        }

        $preview = $this->compilePreviewWithSize(
            $slug,
            $this->buildGovBrLikeLayoutHtml($logoPath),
            $width,
            $height,
            $previewRoot,
        );

        return $preview + ['logoPath' => $logoPath];
    }

    /**
     * @return array{result: \LibreSign\XObjectTemplate\Dto\CompileResult, pdf: string, previewPath: string}
     */
    private function compilePreviewWithSize(
        string $slug,
        string $html,
        float $width,
        float $height,
        string $previewRoot,
    ): array {
        $this->ensureDirectoryExists($previewRoot);
        $this->removeLegacyPreviewPngs($previewRoot, $slug);

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

    private function buildLayoutHtml(string $layout, string $backgroundPath, ?string $signaturePath): string
    {
        $background = sprintf(
            '<img src="%s" style="position:absolute;left:0;top:0;width:100%%;height:100%%" />',
            $this->escapeAttribute($backgroundPath),
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
                $this->requireSignaturePath($signaturePath),
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
                $this->requireSignaturePath($signaturePath),
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
                $this->requireSignaturePath($signaturePath),
            ),
            default => throw new \InvalidArgumentException(sprintf('Unknown visible stamp layout "%s".', $layout)),
        };
    }

    private function buildGovBrLikeLayoutHtml(string $logoPath): string
    {
        return sprintf(
            '<div style="display:flex;flex-direction:row;align-items:center;width:100%%;height:100%%;'
            . 'padding:18px 20px;background-color:#ffffff;border-color:#ef4d3f;border-width:1;border-radius:2">'
            . '<div style="display:flex;justify-content:center;align-items:center;width:28%%;height:100%%">'
            . '<img src="%s" style="width:190px;height:58px" />'
            . '</div>'
            . '<div style="width:72%%;height:100%%;padding:2px 0 0 10px">'
            . '<div style="font-size:15px;line-height:17px;color:#666666">Documento assinado digitalmente</div>'
            . '<div style="font-size:24px;line-height:28px;font-weight:700;color:#000000;'
            . 'margin:7px 0 0 0">ASSINANTE DE EXEMPLO</div>'
            . '<div style="font-size:16px;line-height:18px;color:#666666;'
            . 'margin:10px 0 0 0">Data: 01/01/2026 12:00:00-0300</div>'
            . '<div style="font-size:16px;line-height:18px;color:#666666;'
            . 'margin:7px 0 0 0">Verifique em https://verificador.iti.br</div>'
            . '</div>'
            . '</div>',
            $this->escapeAttribute($logoPath),
        );
    }

    private function createBackgroundPreview(string $path): string
    {
        if (is_file($path)) {
            return $path;
        }

        $contents = PngFixtureFactory::createRgbaPngFromPixelRenderer(
            (int) self::DEFAULT_PREVIEW_WIDTH,
            (int) self::DEFAULT_PREVIEW_HEIGHT,
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

    private function createSignaturePreview(string $path): string
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

    private function removeLegacyPreviewPngs(string $previewRoot, string $slug): void
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

    private function requireSignaturePath(?string $signaturePath): string
    {
        if ($signaturePath === null) {
            throw new \InvalidArgumentException('This visible stamp layout requires a signature image.');
        }

        return $this->escapeAttribute($signaturePath);
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        mkdir($directory, 0777, true);
    }

    private function layoutUsesSignatureImage(string $layout): bool
    {
        return in_array(
            $layout,
            ['signature_and_metadata_right', 'signature_centered', 'two_columns_centered_cells'],
            true,
        );
    }

    private function escapeAttribute(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
