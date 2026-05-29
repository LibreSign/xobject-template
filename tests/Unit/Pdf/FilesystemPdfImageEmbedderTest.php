<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf;

use LibreSign\XObjectTemplate\Pdf\FilesystemPdfImageEmbedder;
use LibreSign\XObjectTemplate\Tests\Support\PngFixtureFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class FilesystemPdfImageEmbedderTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $temporaryFile) {
            @unlink($temporaryFile);
        }

        $this->temporaryFiles = [];
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

    #[DataProvider('jpegChannelProvider')]
    public function testEmbedJpegMapsChannelMetadataToPdfColorSpaces(
        int|string $channels,
        string $expectedColorSpace,
    ): void {
        $embedder = new FilesystemPdfImageEmbedder();
        $method = new ReflectionMethod($embedder, 'embedJpeg');

        $image = $method->invoke($embedder, 'jpeg-binary', [0 => 4, 1 => 3, 'channels' => $channels]);

        self::assertSame($expectedColorSpace, $image->dictionary['ColorSpace']);
        self::assertSame(4, $image->dictionary['Width']);
        self::assertSame(3, $image->dictionary['Height']);
        self::assertSame('jpeg-binary', $image->stream);
    }

    public function testEmbedJpegRejectsMissingDimensions(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $method = new ReflectionMethod($embedder, 'embedJpeg');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('JPEG metadata must expose width and height.');

        $method->invoke($embedder, 'jpeg-binary', ['channels' => 3]);
    }

    public function testEmbedJpegRejectsMissingWidthOrHeightIndividually(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $method = new ReflectionMethod($embedder, 'embedJpeg');

        foreach ([[1 => 3, 'channels' => 3], [0 => 4, 'channels' => 3]] as $metadata) {
            try {
                $method->invoke($embedder, 'jpeg-binary', $metadata);
                self::fail('Expected missing JPEG dimensions to be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('JPEG metadata must expose width and height.', $exception->getMessage());
            }
        }
    }

    public function testEmbedJpegDefaultsMissingChannelMetadataToRgb(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $method = new ReflectionMethod($embedder, 'embedJpeg');

        $image = $method->invoke($embedder, 'jpeg-binary', [0 => 4, 1 => 3]);

        self::assertSame('/DeviceRGB', $image->dictionary['ColorSpace']);
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

    public function testEmbedRejectsPngsWithInvalidSignature(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $method = new ReflectionMethod($embedder, 'parsePng');
        $png = PngFixtureFactory::createPng(
            width: 1,
            height: 1,
            colorType: 2,
            scanlines: "\x00\xff\x00\x00",
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid PNG signature.');

        $method->invoke($embedder, 'BROKEN!!' . substr($png, 8));
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
        $trailingDataPath = $this->createTemporaryFile('png', $png . self::createPngChunk('tEXt', 'tail'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PNG data after IEND is not supported.');

        $embedder->embed($trailingDataPath);
    }

    public function testParsePngRejectsAdditionalChunksAfterIend(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $method = new ReflectionMethod($embedder, 'parsePng');
        $png = PngFixtureFactory::createPng(
            width: 1,
            height: 1,
            colorType: 2,
            scanlines: "\x00\xff\x00\x00",
        ) . self::createPngChunk('tEXt', 'tail');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PNG data after IEND is not supported.');

        $method->invoke($embedder, $png);
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

    public function testUnfilterPngRowSupportsAverageFilterWithMultiPixelContext(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $method = new ReflectionMethod($embedder, 'unfilterPngRow');

        $decodedRow = $method->invoke(
            $embedder,
            3,
            "\x55\x5a\x5f\x64\x3c\x3c\x3c\x3c",
            "\x0a\x14\x1e\x28\x32\x3c\x46\x50",
            4,
        );

        self::assertSame("\x5a\x64\x6e\x78\x82\x8c\x96\xa0", $decodedRow);
    }

    public function testUnfilterPngRowSupportsPaethFilterWithMultiPixelContext(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $method = new ReflectionMethod($embedder, 'unfilterPngRow');

        $decodedRow = $method->invoke(
            $embedder,
            4,
            "\x0a\x0a\x0a\x0a\x0a\x14\x1e\x28",
            "\x0a\x14\x1e\x28\x3c\x46\x50\x5a",
            4,
        );

        self::assertSame("\x14\x1e\x28\x32\x46\x5a\x6e\x82", $decodedRow);
    }

    public function testPaethPredictorSelectsExpectedNeighborAcrossTieCases(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $method = new ReflectionMethod($embedder, 'paethPredictor');

        self::assertSame(20, $method->invoke($embedder, 20, 20, 10));
        self::assertSame(50, $method->invoke($embedder, 50, 10, 20));
        self::assertSame(50, $method->invoke($embedder, 10, 50, 20));
        self::assertSame(20, $method->invoke($embedder, 10, 30, 20));
        self::assertSame(0, $method->invoke($embedder, 0, 3, 2));
        self::assertSame(3, $method->invoke($embedder, 0, 3, 1));
    }

    public function testResolveMimeTypeRejectsMissingMimeKey(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $method = new ReflectionMethod($embedder, 'resolveMimeType');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Image metadata for "fixture.png" does not expose a mime type.');

        $method->invoke($embedder, [0 => 1, 1 => 1], 'fixture.png');
    }

    public function testResolveMimeTypeRejectsNonStringMimeValues(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $method = new ReflectionMethod($embedder, 'resolveMimeType');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Image metadata for "fixture.png" must expose the mime type as a string.');

        $method->invoke($embedder, ['mime' => 123], 'fixture.png');
    }

    public function testReadPngChunkRejectsTruncatedLengthField(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $method = new ReflectionMethod($embedder, 'readPngChunk');
        $offset = 0;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid PNG chunk length.');

        $method->invokeArgs($embedder, ["\x00\x00\x00", &$offset]);
    }

    public function testParseChunkLengthPreservesAllFourBytes(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $method = new ReflectionMethod($embedder, 'parseChunkLength');

        self::assertSame(0x01020304, $method->invoke($embedder, "\x01\x02\x03\x04"));
    }

    public function testParsePngRejectsMissingMetadataWhenIhdrChunkIsAbsent(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $method = new ReflectionMethod($embedder, 'parsePng');
        $png = "\x89PNG\r\n\x1a\n"
            . self::createPngChunk('IDAT', PngFixtureFactory::compressScanlines("\x00\xff\x00\x00"))
            . self::createPngChunk('IEND', '');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PNG metadata is incomplete.');

        $method->invoke($embedder, $png);
    }

    public function testParsePngRejectsMissingImageDataWhenIdatChunkIsAbsent(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $method = new ReflectionMethod($embedder, 'parsePng');
        $ihdr = pack('NNCCCCC', 1, 1, 8, 2, 0, 0, 0);
        $png = "\x89PNG\r\n\x1a\n"
            . self::createPngChunk('IHDR', $ihdr)
            . self::createPngChunk('IEND', '');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PNG image data is missing.');

        $method->invoke($embedder, $png);
    }

    public function testReadPngChunkRejectsInvalidChunkTypeLength(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $method = new ReflectionMethod($embedder, 'readPngChunk');
        $offset = 0;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid PNG chunk type.');

        $method->invokeArgs($embedder, [pack('N', 0) . 'ABC', &$offset]);
    }

    public function testReadPngChunkRejectsTruncatedChunkPayload(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $method = new ReflectionMethod($embedder, 'readPngChunk');
        $offset = 0;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PNG chunk data is truncated.');

        $method->invokeArgs($embedder, [pack('N', 1) . 'IHDR', &$offset]);
    }

    public function testParsePngHeaderRejectsUnexpectedHeaderLength(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $method = new ReflectionMethod($embedder, 'parsePngHeader');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to parse the PNG IHDR chunk.');

        $method->invoke($embedder, 'short-header');
    }

    public function testUnfilterPngScanlinesRejectsInvalidCompressedData(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $method = new ReflectionMethod($embedder, 'unfilterPngScanlines');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PNG image data could not be decompressed.');

        $method->invoke($embedder, 'not-compressed', 1, 1, 1);
    }

    public function testUnfilterPngScanlinesRejectsMissingRowFilterBytes(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $method = new ReflectionMethod($embedder, 'unfilterPngScanlines');
        $compressed = gzcompress('');
        if (!is_string($compressed)) {
            self::fail('Failed to compress empty scanlines fixture.');
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PNG scanlines are truncated.');

        $method->invoke($embedder, $compressed, 1, 1, 1);
    }

    public function testUnfilterPngScanlinesRejectsMissingRowPayloadBytes(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $method = new ReflectionMethod($embedder, 'unfilterPngScanlines');
        $compressed = gzcompress("\x00");
        if (!is_string($compressed)) {
            self::fail('Failed to compress truncated row scanlines fixture.');
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PNG row data is truncated.');

        $method->invoke($embedder, $compressed, 1, 1, 1);
    }

    public function testUnfilterPngRowSupportsSubFilterWithMultiPixelContext(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $method = new ReflectionMethod($embedder, 'unfilterPngRow');

        $decodedRow = $method->invoke(
            $embedder,
            1,
            "\x05\x06\x02\x03",
            "\x00\x00\x00\x00",
            2,
        );

        self::assertSame("\x05\x06\x07\x09", $decodedRow);
    }

    public function testUnfilterPngRowSupportsUpFilterWithPriorRowContext(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $method = new ReflectionMethod($embedder, 'unfilterPngRow');

        $decodedRow = $method->invoke(
            $embedder,
            2,
            "\x05\x06\x07\x08",
            "\x01\x02\x03\x04",
            2,
        );

        self::assertSame("\x06\x08\x0a\x0c", $decodedRow);
    }

    public function testUnfilterPngRowUsesPreviousUpperLeftAtBoundary(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $method = new ReflectionMethod($embedder, 'unfilterPngRow');

        $decodedRow = $method->invoke(
            $embedder,
            4,
            "\xff\x00",
            "\x01\x01",
            1,
        );

        self::assertSame('0000', bin2hex((string) $decodedRow));
    }

    public function testUnfilterPngRowUsesZeroUpperLeftFallbackForFirstByte(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $method = new ReflectionMethod($embedder, 'unfilterPngRow');

        $decodedRow = $method->invoke(
            $embedder,
            4,
            "\x00",
            "\x01",
            1,
        );

        self::assertSame("\x01", $decodedRow);
    }

    public function testParsePngRejectsMissingTrailerChunkWhenTrailingBytesAreTooShort(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $method = new ReflectionMethod($embedder, 'parsePng');
        $png = PngFixtureFactory::createPng(
            width: 1,
            height: 1,
            colorType: 2,
            scanlines: "\x00\xff\x00\x00",
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PNG trailer chunk is missing.');

        $method->invoke($embedder, substr($png, 0, -12) . "\x00\x00\x00");
    }

    public function testAssertReadableSourceRejectsUnreadableFiles(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $method = new ReflectionMethod($embedder, 'assertReadableSource');
        $path = $this->createTemporaryFile('png', 'contents');
        chmod($path, 0);

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage(sprintf('Image source "%s" must be readable.', $path));

            $method->invoke($embedder, $path);
        } finally {
            chmod($path, 0644);
        }
    }

    public function testReadSourceContentsRejectsUnreadableSources(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $method = new ReflectionMethod($embedder, 'readSourceContents');
        $missingPath = sys_get_temp_dir() . '/xobject-template-missing-image.bin';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('Failed to read image source "%s".', $missingPath));

        $method->invoke($embedder, $missingPath);
    }

    public function testAssertNoPngChunksAfterIendRejectsAdditionalChunks(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $method = new ReflectionMethod($embedder, 'assertNoPngChunksAfterIend');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PNG data after IEND is not supported.');

        $method->invoke($embedder, 12);
    }

    public function testAssertPngEndsAtIendRejectsTrailingData(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();
        $method = new ReflectionMethod($embedder, 'assertPngEndsAtIend');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PNG data after IEND is not supported.');

        $method->invoke($embedder, 12, 16);
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
     * @return iterable<string, array{channels: int|string, expectedColorSpace: string}>
     */
    public static function jpegChannelProvider(): iterable
    {
        yield 'grayscale' => ['channels' => 1, 'expectedColorSpace' => '/DeviceGray'];
        yield 'rgb default' => ['channels' => 3, 'expectedColorSpace' => '/DeviceRGB'];
        yield 'cmyk' => ['channels' => 4, 'expectedColorSpace' => '/DeviceCMYK'];
        yield 'invalid channel metadata defaults to rgb' => ['channels' => '4', 'expectedColorSpace' => '/DeviceRGB'];
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

    private function createTemporaryFile(string $extension, string $contents): string
    {
        $temporaryFile = tempnam(sys_get_temp_dir(), 'xot_');
        if ($temporaryFile === false) {
            self::fail('Failed to create a temporary file for image export tests.');
        }

        $pathWithExtension = $temporaryFile . '.' . $extension;
        rename($temporaryFile, $pathWithExtension);
        file_put_contents($pathWithExtension, $contents);
        $this->temporaryFiles[] = $pathWithExtension;

        return $pathWithExtension;
    }

    private static function createPngChunk(string $type, string $data): string
    {
        $crc = crc32($type . $data);
        if ($crc < 0) {
            $crc += 4_294_967_296;
        }

        return pack('N', strlen($data))
            . $type
            . $data
            . pack('N', $crc);
    }
}
