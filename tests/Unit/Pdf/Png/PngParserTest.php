<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf\Png;

use LibreSign\XObjectTemplate\Pdf\Png\PngParser;
use LibreSign\XObjectTemplate\Tests\Support\PngNamespaceFunctionOverrides;
use LibreSign\XObjectTemplate\Tests\Support\PngFixtureFactory;
use PHPUnit\Framework\TestCase;

final class PngParserTest extends TestCase
{
    protected function tearDown(): void
    {
        PngNamespaceFunctionOverrides::reset();
    }

    public function testParseReturnsStructuredImageData(): void
    {
        $parser = new PngParser();
        $compressed = PngFixtureFactory::compressScanlines("\x00\xff\x00\x00");
        $png = PngFixtureFactory::createPngFromCompressedIdatChunks(
            width: 1,
            height: 1,
            colorType: 2,
            idatChunks: [$compressed],
        );

        $parsed = $parser->parse($png);

        self::assertSame(1, $parsed->width);
        self::assertSame(1, $parsed->height);
        self::assertSame(2, $parsed->colorType);
        self::assertSame($compressed, $parsed->idat);
    }

    public function testParseRejectsInvalidSignature(): void
    {
        $parser = new PngParser();
        $png = PngFixtureFactory::createPng(
            width: 1,
            height: 1,
            colorType: 2,
            scanlines: "\x00\xff\x00\x00",
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid PNG signature.');

        $parser->parse('BROKEN!!' . substr($png, 8));
    }

    public function testParseRejectsTrailingDataAfterIendChunk(): void
    {
        $parser = new PngParser();
        $png = PngFixtureFactory::createPng(
            width: 1,
            height: 1,
            colorType: 2,
            scanlines: "\x00\xff\x00\x00",
        ) . PngFixtureFactory::createChunk('tEXt', 'tail');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PNG data after IEND is not supported.');

        $parser->parse($png);
    }

    public function testParseRejectsTrailingBytesAfterIend(): void
    {
        $parser = new PngParser();
        $png = PngFixtureFactory::createPng(
            width: 1,
            height: 1,
            colorType: 2,
            scanlines: "\x00\xff\x00\x00",
        ) . "\x00\x00\x00";

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PNG data after IEND is not supported.');

        $parser->parse($png);
    }

    public function testReadChunkRejectsTruncatedLengthField(): void
    {
        $parser = new PngParser();
        $offset = 0;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid PNG chunk length.');

        $parser->readChunk("\x00\x00\x00", $offset);
    }

    public function testParseChunkLengthPreservesAllFourBytes(): void
    {
        $parser = new PngParser();

        self::assertSame(0x01020304, $parser->parseChunkLength("\x01\x02\x03\x04"));
    }

    public function testParseRejectsMissingMetadataWhenIhdrChunkIsAbsent(): void
    {
        $parser = new PngParser();
        $png = "\x89PNG\r\n\x1a\n"
            . PngFixtureFactory::createChunk('IDAT', PngFixtureFactory::compressScanlines("\x00\xff\x00\x00"))
            . PngFixtureFactory::createChunk('IEND', '');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PNG metadata is incomplete.');

        $parser->parse($png);
    }

    public function testParseRejectsMissingImageDataWhenIdatChunkIsAbsent(): void
    {
        $parser = new PngParser();
        $ihdr = pack('NNCCCCC', 1, 1, 8, 2, 0, 0, 0);
        $png = "\x89PNG\r\n\x1a\n"
            . PngFixtureFactory::createChunk('IHDR', $ihdr)
            . PngFixtureFactory::createChunk('IEND', '');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PNG image data is missing.');

        $parser->parse($png);
    }

    public function testReadChunkRejectsInvalidChunkTypeLength(): void
    {
        $parser = new PngParser();
        $offset = 0;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid PNG chunk type.');

        $parser->readChunk(pack('N', 0) . 'ABC', $offset);
    }

    public function testReadChunkRejectsTruncatedChunkPayload(): void
    {
        $parser = new PngParser();
        $offset = 0;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PNG chunk data is truncated.');

        $parser->readChunk(pack('N', 1) . 'IHDR', $offset);
    }

    public function testParseHeaderRejectsUnexpectedHeaderLength(): void
    {
        $parser = new PngParser();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to parse the PNG IHDR chunk.');

        $parser->parseHeader('short-header');
    }

    public function testParseHeaderRejectsUnpackFailures(): void
    {
        PngNamespaceFunctionOverrides::overrideUnpack(static fn (): false => false);
        $parser = new PngParser();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to parse the PNG IHDR chunk.');

        $parser->parseHeader(pack('NNCCCCC', 1, 1, 8, 2, 0, 0, 0));
    }

    public function testParseRejectsMissingTrailerChunkWhenTrailingBytesAreTooShort(): void
    {
        $parser = new PngParser();
        $png = PngFixtureFactory::createPng(
            width: 1,
            height: 1,
            colorType: 2,
            scanlines: "\x00\xff\x00\x00",
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PNG trailer chunk is missing.');

        $parser->parse(substr($png, 0, -12) . "\x00\x00\x00");
    }
}
