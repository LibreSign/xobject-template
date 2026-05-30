<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf\Png;

use LibreSign\XObjectTemplate\Pdf\Png\ParsedPngImage;
use LibreSign\XObjectTemplate\Pdf\Png\PngParserInterface;
use LibreSign\XObjectTemplate\Pdf\Png\PngPdfImageFactory;
use LibreSign\XObjectTemplate\Pdf\Png\PngScanlineCompressorInterface;
use LibreSign\XObjectTemplate\Pdf\Png\PngScanlineUnfiltererInterface;
use PHPUnit\Framework\TestCase;

final class PngPdfImageFactoryTest extends TestCase
{
    public function testCreateUsesInjectedParserForOpaqueImages(): void
    {
        $factory = new PngPdfImageFactory(
            new class implements PngParserInterface {
                public function parse(string $contents): ParsedPngImage
                {
                    return new ParsedPngImage(1, 1, 2, 'opaque-idat');
                }
            },
            new class implements PngScanlineUnfiltererInterface {
                public function unfilter(string $idat, int $height, int $rowLength, int $bytesPerPixel): array
                {
                    throw new \RuntimeException('Scanline unfiltering should not be used for opaque PNG images.');
                }
            },
        );

        $image = $factory->create('not-a-real-png');

        self::assertSame('/DeviceRGB', $image->dictionary['ColorSpace']);
        self::assertSame('opaque-idat', $image->stream);
        self::assertNull($image->softMask);
    }

    public function testCreateUsesInjectedScanlineUnfiltererForAlphaImages(): void
    {
        $factory = new PngPdfImageFactory(
            new class implements PngParserInterface {
                public function parse(string $contents): ParsedPngImage
                {
                    return new ParsedPngImage(1, 1, 6, 'ignored-compressed-idat');
                }
            },
            new class implements PngScanlineUnfiltererInterface {
                public function unfilter(string $idat, int $height, int $rowLength, int $bytesPerPixel): array
                {
                    return ["\xff\x00\x00\x80"];
                }
            },
        );

        $image = $factory->create('not-a-real-png');

        self::assertNotNull($image->softMask);
        self::assertSame("\x00\xff\x00\x00", gzuncompress($image->stream));
        self::assertSame("\x00\x80", gzuncompress($image->softMask->stream));
    }

    public function testCreateRejectsUnsupportedColorTypes(): void
    {
        $factory = new PngPdfImageFactory(
            new class implements PngParserInterface {
                public function parse(string $contents): ParsedPngImage
                {
                    return new ParsedPngImage(1, 1, 3, 'opaque-idat');
                }
            },
            new class implements PngScanlineUnfiltererInterface {
                public function unfilter(string $idat, int $height, int $rowLength, int $bytesPerPixel): array
                {
                    throw new \RuntimeException('Unsupported color types should fail before unfiltering.');
                }
            },
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported PNG color type 3.');

        $factory->create('not-a-real-png');
    }

    public function testCreateRejectsTruncatedAlphaPixelData(): void
    {
        $factory = new PngPdfImageFactory(
            new class implements PngParserInterface {
                public function parse(string $contents): ParsedPngImage
                {
                    return new ParsedPngImage(1, 1, 6, 'ignored-compressed-idat');
                }
            },
            new class implements PngScanlineUnfiltererInterface {
                public function unfilter(string $idat, int $height, int $rowLength, int $bytesPerPixel): array
                {
                    return ["\xff\x00\x00"];
                }
            },
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PNG row data is truncated.');

        $factory->create('not-a-real-png');
    }

    public function testCreateRejectsCompressionFailuresForAlphaImages(): void
    {
        $factory = new PngPdfImageFactory(
            new class implements PngParserInterface {
                public function parse(string $contents): ParsedPngImage
                {
                    return new ParsedPngImage(1, 1, 6, 'ignored-compressed-idat');
                }
            },
            new class implements PngScanlineUnfiltererInterface {
                public function unfilter(string $idat, int $height, int $rowLength, int $bytesPerPixel): array
                {
                    return ["\xff\x00\x00\x80"];
                }
            },
            new class implements PngScanlineCompressorInterface {
                public function compress(string $scanlines): string|false
                {
                    return false;
                }
            },
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PNG scanlines could not be compressed.');

        $factory->create('not-a-real-png');
    }
}
