<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf;

use LibreSign\XObjectTemplate\Pdf\EmbeddedPdfImage;
use LibreSign\XObjectTemplate\Pdf\FilesystemPdfImageEmbedder;
use LibreSign\XObjectTemplate\Pdf\FilesystemImageSourceReaderInterface;
use LibreSign\XObjectTemplate\Pdf\ImageMetadataInspectorInterface;
use LibreSign\XObjectTemplate\Pdf\Jpeg\JpegPdfImageFactoryInterface;
use LibreSign\XObjectTemplate\Pdf\Png\PngPdfImageFactoryInterface;
use LibreSign\XObjectTemplate\Pdf\Svg\SvgPdfXObjectFactoryInterface;
use LibreSign\XObjectTemplate\Tests\Support\PngFixtureFactory;
use LibreSign\XObjectTemplate\Tests\Support\UsesTemporaryFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FilesystemPdfImageEmbedderTest extends TestCase
{
    use UsesTemporaryFiles;

    protected function tearDown(): void
    {
        $this->tearDownTemporaryFiles();
    }

    public function testEmbedUsesInjectedCollaboratorsForJpegImages(): void
    {
        $expectedImage = new EmbeddedPdfImage(['Type' => '/Image'], 'jpeg-stream');
        $embedder = new FilesystemPdfImageEmbedder(
            new class implements FilesystemImageSourceReaderInterface {
                public function read(string $source): string
                {
                    return 'jpeg-binary';
                }
            },
            new class implements ImageMetadataInspectorInterface {
                public function detect(string $contents, string $source): array
                {
                    return [0 => 1, 1 => 1, 'mime' => 'image/jpeg'];
                }

                public function resolveMimeType(array $imageInfo, string $source): string
                {
                    return 'image/jpeg';
                }
            },
            new class ($expectedImage) implements JpegPdfImageFactoryInterface {
                public function __construct(private readonly EmbeddedPdfImage $expectedImage)
                {
                }

                public function create(string $contents, array $imageInfo): EmbeddedPdfImage
                {
                    return $this->expectedImage;
                }
            },
            new class implements PngPdfImageFactoryInterface {
                public function create(string $contents): EmbeddedPdfImage
                {
                    throw new \RuntimeException('PNG factory should not be used for JPEG images.');
                }
            },
        );

        self::assertSame($expectedImage, $embedder->embed('/tmp/virtual-image.jpg'));
    }

    public function testEmbedUsesInjectedCollaboratorsForPngImages(): void
    {
        $expectedImage = new EmbeddedPdfImage(['Type' => '/Image'], 'png-stream');
        $embedder = new FilesystemPdfImageEmbedder(
            new class implements FilesystemImageSourceReaderInterface {
                public function read(string $source): string
                {
                    return 'not-a-real-png';
                }
            },
            new class implements ImageMetadataInspectorInterface {
                public function detect(string $contents, string $source): array
                {
                    return [0 => 1, 1 => 1, 'mime' => 'image/png'];
                }

                public function resolveMimeType(array $imageInfo, string $source): string
                {
                    return 'image/png';
                }
            },
            new class implements JpegPdfImageFactoryInterface {
                public function create(string $contents, array $imageInfo): EmbeddedPdfImage
                {
                    throw new \RuntimeException('JPEG factory should not be used for PNG images.');
                }
            },
            new class ($expectedImage) implements PngPdfImageFactoryInterface {
                public function __construct(private readonly EmbeddedPdfImage $expectedImage)
                {
                }

                public function create(string $contents): EmbeddedPdfImage
                {
                    return $this->expectedImage;
                }
            },
        );

        self::assertSame($expectedImage, $embedder->embed('/tmp/virtual-image.png'));
    }

    public function testEmbedSupportsSvgSourcesViaInjectedFactory(): void
    {
        $expectedImage = new EmbeddedPdfImage(['Type' => '/XObject', 'Subtype' => '/Form'], 'svg-form-stream');
        $embedder = new FilesystemPdfImageEmbedder(
            new class implements FilesystemImageSourceReaderInterface {
                public function read(string $source): string
                {
                    return '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"></svg>';
                }
            },
            new class implements ImageMetadataInspectorInterface {
                public function detect(string $contents, string $source): array
                {
                    throw new \RuntimeException('Metadata inspector should not run for native SVG embeds.');
                }

                public function resolveMimeType(array $imageInfo, string $source): string
                {
                    throw new \RuntimeException('MIME resolution should not run for native SVG embeds.');
                }
            },
            new class implements JpegPdfImageFactoryInterface {
                public function create(string $contents, array $imageInfo): EmbeddedPdfImage
                {
                    throw new \RuntimeException('JPEG factory should not be used for SVG images.');
                }
            },
            new class ($expectedImage) implements PngPdfImageFactoryInterface {
                public function __construct(private readonly EmbeddedPdfImage $expectedImage)
                {
                }

                public function create(string $contents): EmbeddedPdfImage
                {
                    throw new \RuntimeException('PNG factory should not be used for SVG images.');
                }
            },
            new class ($expectedImage) implements SvgPdfXObjectFactoryInterface {
                public function __construct(private readonly EmbeddedPdfImage $expectedImage)
                {
                }

                public function create(string $svgContents, string $source): EmbeddedPdfImage
                {
                    if (str_contains($svgContents, '<svg') === false || $source !== '/tmp/virtual-image.svg') {
                        throw new \RuntimeException('Unexpected SVG payload for native SVG embedding.');
                    }

                    return $this->expectedImage;
                }
            },
        );

        self::assertSame($expectedImage, $embedder->embed('/tmp/virtual-image.svg'));
    }

    public function testEmbedReturnsPredictorBackedImageForOpaqueRgbPng(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $pngPath = $this->createTemporaryFile('png', PngFixtureFactory::createPng(
            width: 1,
            height: 1,
            colorType: 2,
            scanlines: "\x00\xff\x00\x00",
        ));

        $image = $embedder->embed($pngPath);

        self::assertSame('/XObject', $image->dictionary['Type']);
        self::assertSame('/Image', $image->dictionary['Subtype']);
        self::assertSame(1, $image->dictionary['Width']);
        self::assertSame(1, $image->dictionary['Height']);
        self::assertSame('/DeviceRGB', $image->dictionary['ColorSpace']);
        self::assertSame(8, $image->dictionary['BitsPerComponent']);
        self::assertSame('/FlateDecode', $image->dictionary['Filter']);
        self::assertSame([
            'Predictor' => 15,
            'Colors' => 3,
            'BitsPerComponent' => 8,
            'Columns' => 1,
        ], $image->dictionary['DecodeParms']);
        self::assertNull($image->softMask);
        self::assertNotSame('', $image->stream);
    }

    public function testEmbedCreatesSoftMaskForRgbaPng(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $pngPath = $this->createTemporaryFile('png', PngFixtureFactory::createPng(
            width: 1,
            height: 1,
            colorType: 6,
            scanlines: "\x00\xff\x00\x00\x80",
        ));

        $image = $embedder->embed($pngPath);

        self::assertSame('/DeviceRGB', $image->dictionary['ColorSpace']);
        self::assertNotNull($image->softMask);
        self::assertSame('/XObject', $image->softMask->dictionary['Type']);
        self::assertSame('/Image', $image->softMask->dictionary['Subtype']);
        self::assertSame('/DeviceGray', $image->softMask->dictionary['ColorSpace']);
        self::assertSame(1, $image->softMask->dictionary['Width']);
        self::assertSame(1, $image->softMask->dictionary['Height']);
        self::assertSame('/FlateDecode', $image->softMask->dictionary['Filter']);
    }

    public function testEmbedSupportsOpaqueGrayscalePng(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $pngPath = $this->createTemporaryFile('png', PngFixtureFactory::createPng(
            width: 1,
            height: 1,
            colorType: 0,
            scanlines: "\x00\x80",
        ));

        $image = $embedder->embed($pngPath);

        self::assertSame('/DeviceGray', $image->dictionary['ColorSpace']);
        self::assertSame(1, $image->dictionary['DecodeParms']['Colors']);
        self::assertSame(8, $image->dictionary['DecodeParms']['BitsPerComponent']);
        self::assertNull($image->softMask);
        self::assertSame("\x00\x80", gzuncompress($image->stream));
    }

    public function testEmbedCreatesSoftMaskForGrayAlphaPng(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $pngPath = $this->createTemporaryFile('png', PngFixtureFactory::createPng(
            width: 1,
            height: 1,
            colorType: 4,
            scanlines: "\x00\x80\x40",
        ));

        $image = $embedder->embed($pngPath);

        self::assertSame('/DeviceGray', $image->dictionary['ColorSpace']);
        self::assertNotNull($image->softMask);
        self::assertSame(1, $image->softMask->dictionary['DecodeParms']['Colors']);
        self::assertSame(8, $image->softMask->dictionary['BitsPerComponent']);
        self::assertSame("\x00\x80", gzuncompress($image->stream));
        self::assertSame("\x00\x40", gzuncompress($image->softMask->stream));
    }

    #[DataProvider('rgbaFilterProvider')]
    public function testEmbedSupportsAllRgbaPredictorFilters(int $filterType): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $pngPath = $this->createTemporaryFile('png', PngFixtureFactory::createPng(
            width: 1,
            height: 1,
            colorType: 6,
            scanlines: chr($filterType) . "\xff\x00\x00\x80",
        ));

        $image = $embedder->embed($pngPath);

        self::assertNotNull($image->softMask);
        self::assertSame("\x00\xff\x00\x00", gzuncompress($image->stream));
        self::assertSame("\x00\x80", gzuncompress($image->softMask->stream));
    }

    #[DataProvider('unsupportedPngHeaderProvider')]
    public function testEmbedRejectsUnsupportedPngHeaders(int $bitDepth, int $interlace, string $expectedMessage): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $pngPath = $this->createTemporaryFile('png', PngFixtureFactory::createPng(
            width: 1,
            height: 1,
            colorType: 2,
            scanlines: "\x00\xff\x00\x00",
            bitDepth: $bitDepth,
            interlace: $interlace,
        ));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        $embedder->embed($pngPath);
    }

    public function testEmbedRejectsUnsupportedFormats(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $gifContents = base64_decode('R0lGODdhAQABAIAAAP///////ywAAAAAAQABAAACAkQBADs=', true);
        if ($gifContents === false) {
            self::fail('Failed to decode the embedded GIF fixture.');
        }

        $gifPath = $this->createTemporaryFile('gif', $gifContents);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported image format "image/gif".');

        $embedder->embed($gifPath);
    }

    public function testEmbedSupportsJpegStreams(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $jpegContents = base64_decode(
            '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRof'
            . 'Hh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwh'
            . 'MjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAAR'
            . 'CAABAAEDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAA'
            . 'AgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkK'
            . 'FhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWG'
            . 'h4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl'
            . '5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREA'
            . 'AgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYk'
            . 'NOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOE'
            . 'hYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk'
            . '5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwDi6KKK+ZP3E//Z',
            true,
        );
        if ($jpegContents === false) {
            self::fail('Failed to decode the embedded JPEG fixture.');
        }

        $jpegPath = $this->createTemporaryFile('jpg', $jpegContents);

        $image = $embedder->embed($jpegPath);

        self::assertSame('/XObject', $image->dictionary['Type']);
        self::assertSame('/Image', $image->dictionary['Subtype']);
        self::assertSame('/DCTDecode', $image->dictionary['Filter']);
        self::assertSame('/DeviceRGB', $image->dictionary['ColorSpace']);
        self::assertSame(8, $image->dictionary['BitsPerComponent']);
        self::assertSame(1, $image->dictionary['Width']);
        self::assertSame(1, $image->dictionary['Height']);
        self::assertSame($jpegContents, $image->stream);
        self::assertNull($image->softMask);
    }

    public function testEmbedRejectsUnreadableFiles(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an existing file');

        $embedder->embed('/tmp/does-not-exist-preview.png');
    }

    public function testEmbedRejectsUnknownBinaryPayloads(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $binaryPath = $this->createTemporaryFile('bin', 'not-an-image');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to detect the image format');

        $embedder->embed($binaryPath);
    }

    public function testEmbedRejectsUnsupportedRowFilters(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $pngPath = $this->createTemporaryFile('png', PngFixtureFactory::createPng(
            width: 1,
            height: 1,
            colorType: 6,
            scanlines: "\x05\xff\x00\x00\x80",
        ));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported PNG row filter 5.');

        $embedder->embed($pngPath);
    }

    public function testEmbedRejectsTrailingDataAfterIendChunk(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $png = PngFixtureFactory::createPng(
            width: 1,
            height: 1,
            colorType: 2,
            scanlines: "\x00\xff\x00\x00",
        );
        $trailingDataPath = $this->createTemporaryFile(
            'png',
            $png . PngFixtureFactory::createChunk('tEXt', 'tail'),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PNG data after IEND is not supported.');

        $embedder->embed($trailingDataPath);
    }

    public function testEmbedRejectsUnsupportedPngCompressionAndFilterMethods(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $compressionPath = $this->createTemporaryFile('png', PngFixtureFactory::createPng(
            width: 1,
            height: 1,
            colorType: 2,
            scanlines: "\x00\xff\x00\x00",
            compression: 1,
        ));

        try {
            $embedder->embed($compressionPath);
            self::fail('Expected unsupported compression to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Unsupported PNG compression or filter method.', $exception->getMessage());
        }

        $filterPath = $this->createTemporaryFile('png', PngFixtureFactory::createPng(
            width: 1,
            height: 1,
            colorType: 2,
            scanlines: "\x00\xff\x00\x00",
            filter: 1,
        ));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported PNG compression or filter method.');

        $embedder->embed($filterPath);
    }

    public function testEmbedRejectsUnsupportedPngColorTypes(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $pngPath = $this->createTemporaryFile('png', PngFixtureFactory::createPng(
            width: 1,
            height: 1,
            colorType: 3,
            scanlines: "\x00\x00",
        ));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported PNG color type 3.');

        $embedder->embed($pngPath);
    }

    public function testEmbedConcatenatesMultipleIdatChunks(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $compressed = PngFixtureFactory::compressScanlines("\x00\xff\x00\x00");
        $splitAt = intdiv(strlen($compressed), 2);
        $pngPath = $this->createTemporaryFile('png', PngFixtureFactory::createPngFromCompressedIdatChunks(
            width: 1,
            height: 1,
            colorType: 2,
            idatChunks: [
                substr($compressed, 0, $splitAt),
                substr($compressed, $splitAt),
            ],
        ));

        $image = $embedder->embed($pngPath);

        self::assertSame($compressed, $image->stream);
    }

    public function testEmbedSeparatesMultiRowRgbaPixelsIntoColorAndAlphaStreams(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $pngPath = $this->createTemporaryFile('png', PngFixtureFactory::createRgbaPngFromPixelRenderer(
            width: 2,
            height: 2,
            pixelRenderer: static fn (int $x, int $y): array => match ([$x, $y]) {
                [0, 0] => [255, 0, 0, 64],
                [1, 0] => [0, 255, 0, 128],
                [0, 1] => [0, 0, 255, 192],
                default => [255, 255, 255, 255],
            },
        ));

        $image = $embedder->embed($pngPath);

        self::assertNotNull($image->softMask);
        self::assertSame(
            "\x00\xff\x00\x00\x00\xff\x00\x00\x00\x00\xff\xff\xff\xff",
            gzuncompress($image->stream),
        );
        self::assertSame("\x00\x40\x80\x00\xc0\xff", gzuncompress($image->softMask->stream));
    }

    #[DataProvider('svgExtensionBoundaryProvider')]
    public function testEmbedCorrectlyDetectsSvgByFileExtensionBoundary(string $source, bool $shouldBeSvg): void
    {
        $embeddedImage = new EmbeddedPdfImage(
            $shouldBeSvg ? ['Type' => '/XObject', 'Subtype' => '/Form'] : ['Type' => '/Image'],
            'stream-content',
        );

        $embedder = new FilesystemPdfImageEmbedder(
            new class ($source) implements FilesystemImageSourceReaderInterface {
                public function __construct(private readonly string $expectedSource)
                {
                }

                public function read(string $source): string
                {
                    if ($source !== $this->expectedSource) {
                        throw new \RuntimeException(sprintf('Unexpected source: %s', $source));
                    }
                    // Return PNG binary regardless
                    return "\x89PNG\r\n\x1a\n";
                }
            },
            new class implements ImageMetadataInspectorInterface {
                public function detect(string $contents, string $source): array
                {
                    return [];
                }

                public function resolveMimeType(array $imageInfo, string $source): string
                {
                    return 'image/png';
                }
            },
            new class implements JpegPdfImageFactoryInterface {
                public function create(string $contents, array $imageInfo): EmbeddedPdfImage
                {
                    throw new \RuntimeException('JPEG factory should not be used.');
                }
            },
            new class ($embeddedImage) implements PngPdfImageFactoryInterface {
                public function __construct(private readonly EmbeddedPdfImage $image)
                {
                }

                public function create(string $contents): EmbeddedPdfImage
                {
                    return $this->image;
                }
            },
            new class ($embeddedImage) implements SvgPdfXObjectFactoryInterface {
                public function __construct(private readonly EmbeddedPdfImage $image)
                {
                }

                public function create(string $svgContents, string $source): EmbeddedPdfImage
                {
                    return $this->image;
                }
            },
        );

        $image = $embedder->embed($source);

        if ($shouldBeSvg) {
            self::assertSame('/XObject', $image->dictionary['Type']);
            self::assertSame('/Form', $image->dictionary['Subtype']);
        } else {
            self::assertSame('/Image', $image->dictionary['Type']);
        }
    }

    /**
     * @return iterable<string, array{source: string, shouldBeSvg: bool}>
     */
    public static function svgExtensionBoundaryProvider(): iterable
    {
        yield 'SVG extension detected' => ['source' => '/path/to/file.svg', 'shouldBeSvg' => true];
        yield 'SVGZ extension detected' => ['source' => '/path/to/file.svgz', 'shouldBeSvg' => true];
        yield 'SVG extension case-insensitive uppercase' => ['source' => '/path/to/file.SVG', 'shouldBeSvg' => true];
        yield 'SVG extension case-insensitive mixed' => ['source' => '/path/to/file.Svg', 'shouldBeSvg' => true];
        yield 'SVG in middle of filename not detected as SVG' => ['source' => '/path/svg.backup', 'shouldBeSvg' => false];
        yield 'SVG in filename but different extension' => ['source' => '/path/my.svg.txt', 'shouldBeSvg' => false];
    }

    /**
     * @return iterable<string, array{filterType: int}>
     */
    public static function rgbaFilterProvider(): iterable
    {
        yield 'sub filter' => ['filterType' => 1];
        yield 'up filter' => ['filterType' => 2];
        yield 'average filter' => ['filterType' => 3];
        yield 'paeth filter' => ['filterType' => 4];
    }

    /**
     * @return iterable<string, array{bitDepth: int, interlace: int, expectedMessage: string}>
     */
    public static function unsupportedPngHeaderProvider(): iterable
    {
        yield 'unsupported bit depth' => [
            'bitDepth' => 16,
            'interlace' => 0,
            'expectedMessage' => 'Unsupported PNG bit depth 16.',
        ];

        yield 'unsupported interlace' => [
            'bitDepth' => 8,
            'interlace' => 1,
            'expectedMessage' => 'Interlaced PNG images are not supported.',
        ];
    }
}
