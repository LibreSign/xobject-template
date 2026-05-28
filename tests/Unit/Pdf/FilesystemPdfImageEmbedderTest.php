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
        $gifPath = $this->createTemporaryFile('gif', 'GIF89a');

        $this->expectException(\InvalidArgumentException::class);

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

        self::assertSame('/DCTDecode', $image->dictionary['Filter']);
        self::assertSame('/DeviceRGB', $image->dictionary['ColorSpace']);
        self::assertSame(1, $image->dictionary['Width']);
        self::assertSame(1, $image->dictionary['Height']);
        self::assertSame($jpegContents, $image->stream);
        self::assertNull($image->softMask);
    }

    public function testEmbedRejectsUnreadableFiles(): void
    {
        $embedder = new FilesystemPdfImageEmbedder();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a readable file');

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
}
