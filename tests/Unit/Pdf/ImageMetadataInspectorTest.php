<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf;

use LibreSign\XObjectTemplate\Pdf\ImageMetadataInspector;
use LibreSign\XObjectTemplate\Tests\Support\PngFixtureFactory;
use PHPUnit\Framework\TestCase;

final class ImageMetadataInspectorTest extends TestCase
{
    public function testDetectReturnsImageMetadataForSupportedImages(): void
    {
        $inspector = new ImageMetadataInspector();
        $png = PngFixtureFactory::createPng(
            width: 1,
            height: 1,
            colorType: 2,
            scanlines: "\x00\xff\x00\x00",
        );

        $imageInfo = $inspector->detect($png, 'fixture.png');

        self::assertSame(1, $imageInfo[0]);
        self::assertSame(1, $imageInfo[1]);
        self::assertSame('image/png', $inspector->resolveMimeType($imageInfo, 'fixture.png'));
    }

    public function testDetectRejectsUnknownBinaryPayloads(): void
    {
        $inspector = new ImageMetadataInspector();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to detect the image format for "fixture.bin".');

        $inspector->detect('not-an-image', 'fixture.bin');
    }

    public function testResolveMimeTypeRejectsMissingMimeKey(): void
    {
        $inspector = new ImageMetadataInspector();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Image metadata for "fixture.png" does not expose a mime type.');

        $inspector->resolveMimeType([0 => 1, 1 => 1], 'fixture.png');
    }

    public function testResolveMimeTypeRejectsNonStringMimeValues(): void
    {
        $inspector = new ImageMetadataInspector();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Image metadata for "fixture.png" must expose the mime type as a string.');

        $inspector->resolveMimeType(['mime' => 123], 'fixture.png');
    }
}
