<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf\Png;

use InvalidArgumentException;
use LibreSign\XObjectTemplate\Pdf\Png\ParsedPngImage;
use LibreSign\XObjectTemplate\Pdf\Png\PngParserInterface;

/** @internal */
final readonly class PngParser implements PngParserInterface
{
    private PngHeaderUnpackerInterface $headerUnpacker;

    public function __construct(?PngHeaderUnpackerInterface $headerUnpacker = null)
    {
        $this->headerUnpacker = $headerUnpacker ?? new PhpPngHeaderUnpacker();
    }

    public function parse(string $contents): ParsedPngImage
    {
        $this->assertPngSignature($contents);

        $contentLength = strlen($contents);
        $offset = 8;
        $header = null;
        $idat = '';

        while (($contentLength - $offset) >= 12) {
            ['data' => $data, 'type' => $type] = $this->readChunk($contents, $offset);

            if ($type === 'IHDR') {
                $header = $this->parseHeader($data);
            }

            if ($type === 'IDAT') {
                $idat .= $data;
            }

            if ($type === 'IEND') {
                if ($offset !== $contentLength) {
                    throw new InvalidArgumentException('PNG data after IEND is not supported.');
                }

                if ($header === null) {
                    throw new InvalidArgumentException('PNG metadata is incomplete.');
                }

                $this->assertSupportedHeader($header);

                if ($idat === '') {
                    throw new InvalidArgumentException('PNG image data is missing.');
                }

                return new ParsedPngImage(
                    width: $header['width'],
                    height: $header['height'],
                    colorType: $header['colorType'],
                    idat: $idat,
                );
            }
        }

        throw new InvalidArgumentException('PNG trailer chunk is missing.');
    }

    /**
     * @return array{data: string, type: string}
     */
    public function readChunk(string $contents, int &$offset): array
    {
        $chunkLengthBytes = substr($contents, $offset, 4);
        $chunkLength = $this->parseChunkLength($chunkLengthBytes);

        $offset += 4;
        $type = substr($contents, $offset, 4);
        $offset += 4;

        if (strlen($type) !== 4) {
            throw new InvalidArgumentException('Invalid PNG chunk type.');
        }

        $data = substr($contents, $offset, $chunkLength);
        if (strlen($data) !== $chunkLength) {
            throw new InvalidArgumentException('PNG chunk data is truncated.');
        }

        $offset += $chunkLength + 4;

        return [
            'data' => $data,
            'type' => $type,
        ];
    }

    /**
     * @return array{
     *     width: int,
     *     height: int,
     *     bitDepth: int,
     *     colorType: int,
     *     compression: int,
     *     filter: int,
     *     interlace: int
     * }
     */
    public function parseHeader(string $data): array
    {
        if (strlen($data) !== 13) {
            throw new InvalidArgumentException('Unable to parse the PNG IHDR chunk.');
        }

        $header = $this->headerUnpacker->unpack($data);
        if (!is_array($header)) {
            throw new InvalidArgumentException('Unable to parse the PNG IHDR chunk.');
        }

        return $header;
    }

    public function parseChunkLength(string $chunkLengthBytes): int
    {
        if (strlen($chunkLengthBytes) !== 4) {
            throw new InvalidArgumentException('Invalid PNG chunk length.');
        }

        return (ord($chunkLengthBytes[0]) << 24)
            | (ord($chunkLengthBytes[1]) << 16)
            | (ord($chunkLengthBytes[2]) << 8)
            | ord($chunkLengthBytes[3]);
    }

    private function assertPngSignature(string $contents): void
    {
        if (str_starts_with($contents, "\x89PNG\r\n\x1a\n") === false) {
            throw new InvalidArgumentException('Invalid PNG signature.');
        }
    }

    /**
     * @param array{
     *     width: int,
     *     height: int,
     *     bitDepth: int,
     *     colorType: int,
     *     compression: int,
     *     filter: int,
     *     interlace: int
     * } $header
     */
    private function assertSupportedHeader(array $header): void
    {
        if ($header['bitDepth'] !== 8) {
            throw new InvalidArgumentException(sprintf('Unsupported PNG bit depth %d.', $header['bitDepth']));
        }

        if ($header['compression'] !== 0 || $header['filter'] !== 0) {
            throw new InvalidArgumentException('Unsupported PNG compression or filter method.');
        }

        if ($header['interlace'] !== 0) {
            throw new InvalidArgumentException('Interlaced PNG images are not supported.');
        }
    }
}
