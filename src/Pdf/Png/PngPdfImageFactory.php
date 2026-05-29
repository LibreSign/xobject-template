<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf\Png;

use InvalidArgumentException;
use LibreSign\XObjectTemplate\Pdf\EmbeddedPdfImage;
use LibreSign\XObjectTemplate\Pdf\Png\ParsedPngImage;
use LibreSign\XObjectTemplate\Pdf\Png\PngColorTypeDescription;
use LibreSign\XObjectTemplate\Pdf\Png\PngParser;
use LibreSign\XObjectTemplate\Pdf\Png\PngParserInterface;
use LibreSign\XObjectTemplate\Pdf\Png\PngPdfImageFactoryInterface;
use LibreSign\XObjectTemplate\Pdf\Png\PngScanlineUnfilterer;
use LibreSign\XObjectTemplate\Pdf\Png\PngScanlineUnfiltererInterface;

/** @internal */
final readonly class PngPdfImageFactory implements PngPdfImageFactoryInterface
{
    private PngParserInterface $parser;
    private PngScanlineUnfiltererInterface $scanlineUnfilterer;
    private PngScanlineCompressorInterface $scanlineCompressor;

    public function __construct(
        ?PngParserInterface $parser = null,
        ?PngScanlineUnfiltererInterface $scanlineUnfilterer = null,
        ?PngScanlineCompressorInterface $scanlineCompressor = null,
    ) {
        $this->parser = $parser ?? new PngParser();
        $this->scanlineUnfilterer = $scanlineUnfilterer ?? new PngScanlineUnfilterer();
        $this->scanlineCompressor = $scanlineCompressor ?? new PhpPngScanlineCompressor();
    }

    public function create(string $contents): EmbeddedPdfImage
    {
        $png = $this->parser->parse($contents);
        $colorType = $this->describeColorType($png->colorType);

        if ($colorType->hasAlpha === false) {
            return new EmbeddedPdfImage(
                dictionary: $this->createImageDictionary(
                    $png->width,
                    $png->height,
                    $colorType->colorSpace,
                    $colorType->colorCount,
                ),
                stream: $png->idat,
            );
        }

        [$colorScanlines, $alphaScanlines] = $this->splitAlphaScanlines($png, $colorType);

        return new EmbeddedPdfImage(
            dictionary: $this->createImageDictionary(
                $png->width,
                $png->height,
                $colorType->colorSpace,
                $colorType->colorCount,
            ),
            stream: $this->compressScanlines($colorScanlines),
            softMask: new EmbeddedPdfImage(
                dictionary: $this->createImageDictionary($png->width, $png->height, '/DeviceGray', 1),
                stream: $this->compressScanlines($alphaScanlines),
            ),
        );
    }

    private function describeColorType(int $colorType): PngColorTypeDescription
    {
        return match ($colorType) {
            0 => new PngColorTypeDescription('/DeviceGray', 1, 1, false),
            2 => new PngColorTypeDescription('/DeviceRGB', 3, 3, false),
            4 => new PngColorTypeDescription('/DeviceGray', 1, 2, true),
            6 => new PngColorTypeDescription('/DeviceRGB', 3, 4, true),
            default => throw new InvalidArgumentException(sprintf('Unsupported PNG color type %d.', $colorType)),
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitAlphaScanlines(ParsedPngImage $png, PngColorTypeDescription $colorType): array
    {
        $colorCount = $colorType->colorCount;
        $bytesPerPixel = $colorType->bytesPerPixel;
        $rowLength = $png->width * $bytesPerPixel;
        $unfilteredRows = $this->scanlineUnfilterer->unfilter(
            $png->idat,
            $png->height,
            $rowLength,
            $bytesPerPixel,
        );

        $colorScanlines = '';
        $alphaScanlines = '';
        foreach ($unfilteredRows as $row) {
            $colorRow = '';
            $alphaRow = '';
            foreach (str_split($row, $bytesPerPixel) as $pixel) {
                if (strlen($pixel) !== $bytesPerPixel) {
                    throw new InvalidArgumentException('PNG row data is truncated.');
                }

                $colorRow .= substr($pixel, 0, $colorCount);
                $alphaRow .= $pixel[$bytesPerPixel - 1];
            }

            $colorScanlines .= "\x00" . $colorRow;
            $alphaScanlines .= "\x00" . $alphaRow;
        }

        return [$colorScanlines, $alphaScanlines];
    }

    /**
     * @return array<string, mixed>
     */
    private function createImageDictionary(int $width, int $height, string $colorSpace, int $colorCount): array
    {
        return [
            'Type' => '/XObject',
            'Subtype' => '/Image',
            'Width' => $width,
            'Height' => $height,
            'ColorSpace' => $colorSpace,
            'BitsPerComponent' => 8,
            'Filter' => '/FlateDecode',
            'DecodeParms' => [
                'Predictor' => 15,
                'Colors' => $colorCount,
                'BitsPerComponent' => 8,
                'Columns' => $width,
            ],
        ];
    }

    private function compressScanlines(string $scanlines): string
    {
        $compressed = $this->scanlineCompressor->compress($scanlines);
        if (!is_string($compressed)) {
            throw new InvalidArgumentException('PNG scanlines could not be compressed.');
        }

        return $compressed;
    }
}
