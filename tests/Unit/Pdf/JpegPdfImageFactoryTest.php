<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf;

use LibreSign\XObjectTemplate\Pdf\JpegPdfImageFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JpegPdfImageFactoryTest extends TestCase
{
    #[DataProvider('jpegChannelProvider')]
    public function testCreateMapsChannelMetadataToPdfColorSpaces(
        int|string $channels,
        string $expectedColorSpace,
    ): void {
        $factory = new JpegPdfImageFactory();

        $image = $factory->create('jpeg-binary', [0 => 4, 1 => 3, 'channels' => $channels]);

        self::assertSame($expectedColorSpace, $image->dictionary['ColorSpace']);
        self::assertSame(4, $image->dictionary['Width']);
        self::assertSame(3, $image->dictionary['Height']);
        self::assertSame('jpeg-binary', $image->stream);
    }

    public function testCreateRejectsMissingDimensions(): void
    {
        $factory = new JpegPdfImageFactory();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('JPEG metadata must expose width and height.');

        $factory->create('jpeg-binary', ['channels' => 3]);
    }

    public function testCreateRejectsMissingWidthOrHeightIndividually(): void
    {
        $factory = new JpegPdfImageFactory();

        foreach ([[1 => 3, 'channels' => 3], [0 => 4, 'channels' => 3]] as $metadata) {
            try {
                $factory->create('jpeg-binary', $metadata);
                self::fail('Expected missing JPEG dimensions to be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('JPEG metadata must expose width and height.', $exception->getMessage());
            }
        }
    }

    public function testCreateDefaultsMissingChannelMetadataToRgb(): void
    {
        $factory = new JpegPdfImageFactory();

        $image = $factory->create('jpeg-binary', [0 => 4, 1 => 3]);

        self::assertSame('/DeviceRGB', $image->dictionary['ColorSpace']);
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
}
