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

final class VisibleStampTemplateScenarioTest extends TestCase
{
    private const PREVIEW_WIDTH = 804;
    private const PREVIEW_HEIGHT = 230;

    #[DataProvider('visibleStampLayoutProvider')]
    public function testPhaseOneVisibleStampLayoutsCanBeCompiledAndExported(
        string $slug,
        string $layout,
        int $expectedImageCount,
        array $expectedTexts,
    ): void {
        $previewRoot = dirname(__DIR__, 2) . '/build/visible-stamp-previews';
        $assetRoot = dirname(__DIR__) . '/Fixtures/visible-stamp-assets';
        $this->ensureDirectoryExists($previewRoot);
        $this->ensureDirectoryExists($assetRoot);

        $backgroundPath = $this->createBackgroundPreview($assetRoot . '/background-' . $slug . '.png');
        $signaturePath = $this->layoutUsesSignatureImage($layout)
            ? $this->createSignaturePreview($assetRoot . '/signature-' . $slug . '.png')
            : null;

        $compiler = new XObjectTemplateCompiler();
        $result = $compiler->compile(new CompileRequest(
            html: $this->buildLayoutHtml($layout, $backgroundPath, $signaturePath),
            width: (float) self::PREVIEW_WIDTH,
            height: (float) self::PREVIEW_HEIGHT,
        ));

        $pdf = (new SinglePagePdfExporter())->export($result);
        $previewPath = $previewRoot . '/' . $slug . '.pdf';
        file_put_contents($previewPath, $pdf);

        self::assertSame($expectedImageCount, count($result->resources['XObject'] ?? []));
        self::assertSame((float) self::PREVIEW_WIDTH, $result->resources['XObject']['Im0']['Width']);
        self::assertSame((float) self::PREVIEW_HEIGHT, $result->resources['XObject']['Im0']['Height']);
        self::assertStringStartsWith("%PDF-1.4\n", $pdf);
        self::assertStringContainsString('/Subtype /Form', $pdf);
        self::assertStringContainsString(
            sprintf(
                'q %F 0 0 %F %F %F cm /Im0 Do Q',
                (float) self::PREVIEW_WIDTH,
                (float) self::PREVIEW_HEIGHT,
                0.0,
                0.0,
            ),
            $result->contentStream,
        );
        self::assertStringContainsString('/Im0 Do', $result->contentStream);
        self::assertFileExists($previewPath);
        self::assertSame($pdf, file_get_contents($previewPath));

        if ($expectedImageCount > 1) {
            self::assertStringContainsString('/Im1 Do', $result->contentStream);
        }

        foreach ($expectedTexts as $expectedText) {
            self::assertStringContainsString($expectedText, $result->contentStream);
            self::assertStringContainsString($expectedText, $pdf);
        }
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
            default => throw new \InvalidArgumentException(sprintf('Unknown visible stamp layout "%s".', $layout)),
        };
    }

    private function createBackgroundPreview(string $path): string
    {
        if (is_file($path)) {
            return $path;
        }

        $contents = PngFixtureFactory::createRgbaPngFromPixelRenderer(
            self::PREVIEW_WIDTH,
            self::PREVIEW_HEIGHT,
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
        return in_array($layout, ['signature_and_metadata_right', 'signature_centered'], true);
    }

    private function escapeAttribute(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
