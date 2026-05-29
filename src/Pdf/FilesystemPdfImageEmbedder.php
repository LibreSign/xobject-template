<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf;

use InvalidArgumentException;

/**
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
final readonly class FilesystemPdfImageEmbedder implements PdfImageEmbedderInterface
{
    public function embed(string $source): EmbeddedPdfImage
    {
        $this->assertReadableSource($source);

        $contents = $this->readSourceContents($source);
        $imageInfo = $this->detectImageInfo($contents, $source);
        $mime = $this->resolveMimeType($imageInfo, $source);

        return match ($mime) {
            'image/jpeg' => $this->embedJpeg($contents, $imageInfo),
            'image/png' => $this->embedPng($contents),
            default => throw new InvalidArgumentException(
                sprintf('Unsupported image format "%s".', $mime),
            ),
        };
    }

    /**
     * @param array<int|string, mixed> $imageInfo
     */
    private function embedJpeg(string $contents, array $imageInfo): EmbeddedPdfImage
    {
        $width = $imageInfo[0] ?? null;
        $height = $imageInfo[1] ?? null;

        if (!is_int($width)) {
            throw new InvalidArgumentException('JPEG metadata must expose width and height.');
        }

        if (!is_int($height)) {
            throw new InvalidArgumentException('JPEG metadata must expose width and height.');
        }

        $colorSpace = $this->resolveJpegColorSpace($imageInfo['channels'] ?? null);

        return new EmbeddedPdfImage(
            dictionary: [
                'Type' => '/XObject',
                'Subtype' => '/Image',
                'Width' => $width,
                'Height' => $height,
                'ColorSpace' => $colorSpace,
                'BitsPerComponent' => 8,
                'Filter' => '/DCTDecode',
            ],
            stream: $contents,
        );
    }

    private function embedPng(string $contents): EmbeddedPdfImage
    {
        $png = $this->parsePng($contents);
        [$colorSpace, $colorCount, $hasAlpha] = $this->describePngColorType($png['colorType']);

        if ($hasAlpha === false) {
            return new EmbeddedPdfImage(
                dictionary: $this->createImageDictionary($png['width'], $png['height'], $colorSpace, $colorCount),
                stream: $png['idat'],
            );
        }

        $bytesPerPixel = $colorCount + 1;
        $rowLength = $png['width'] * $bytesPerPixel;
        $unfilteredRows = $this->unfilterPngScanlines($png['idat'], $png['height'], $rowLength, $bytesPerPixel);

        $colorScanlines = '';
        $alphaScanlines = '';
        foreach ($unfilteredRows as $row) {
            $colorRow = '';
            $alphaRow = '';
            for ($offset = 0; $offset < strlen($row); $offset += $bytesPerPixel) {
                $pixel = substr($row, $offset, $bytesPerPixel);
                $colorRow .= substr($pixel, 0, $colorCount);
                $alphaRow .= $pixel[$bytesPerPixel - 1];
            }

            $colorScanlines .= "\x00" . $colorRow;
            $alphaScanlines .= "\x00" . $alphaRow;
        }

        return new EmbeddedPdfImage(
            dictionary: $this->createImageDictionary($png['width'], $png['height'], $colorSpace, $colorCount),
            stream: gzcompress($colorScanlines),
            softMask: new EmbeddedPdfImage(
                dictionary: $this->createImageDictionary($png['width'], $png['height'], '/DeviceGray', 1),
                stream: gzcompress($alphaScanlines),
            ),
        );
    }

    /**
     * @return array{0: string, 1: int, 2: bool}
     */
    private function describePngColorType(int $colorType): array
    {
        return match ($colorType) {
            0 => ['/DeviceGray', 1, false],
            2 => ['/DeviceRGB', 3, false],
            4 => ['/DeviceGray', 1, true],
            6 => ['/DeviceRGB', 3, true],
            default => throw new InvalidArgumentException(sprintf('Unsupported PNG color type %d.', $colorType)),
        };
    }

    /**
     * @return array{width: int, height: int, colorType: int, idat: string}
     */
    private function parsePng(string $contents): array
    {
        $this->assertPngSignature($contents);

        $contentLength = strlen($contents);
        $offset = 8;
        $header = null;
        $idat = '';
        $iendOffset = null;

        while (($contentLength - $offset) >= 12) {
            $this->assertNoPngChunksAfterIend($iendOffset);

            ['data' => $data, 'type' => $type] = $this->readPngChunk($contents, $offset);

            if ($type === 'IHDR') {
                $header = $this->parsePngHeader($data);
            }

            if ($type === 'IDAT') {
                $idat .= $data;
            }

            if ($type === 'IEND') {
                $iendOffset = $offset;
            }
        }

        if ($iendOffset === null) {
            throw new InvalidArgumentException('PNG trailer chunk is missing.');
        }

        $this->assertPngEndsAtIend($iendOffset, $contentLength);

        if ($header === null) {
            throw new InvalidArgumentException('PNG metadata is incomplete.');
        }

        $this->assertSupportedPngHeader($header);

        if ($idat === '') {
            throw new InvalidArgumentException('PNG image data is missing.');
        }

        return [
            'width' => $header['width'],
            'height' => $header['height'],
            'colorType' => $header['colorType'],
            'idat' => $idat,
        ];
    }

    private function assertPngSignature(string $contents): void
    {
        if (str_starts_with($contents, "\x89PNG\r\n\x1a\n") === false) {
            throw new InvalidArgumentException('Invalid PNG signature.');
        }
    }

    /**
     * @return array{data: string, type: string}
     */
    private function readPngChunk(string $contents, int &$offset): array
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
    private function parsePngHeader(string $data): array
    {
        if (strlen($data) !== 13) {
            throw new InvalidArgumentException('Unable to parse the PNG IHDR chunk.');
        }

        $header = unpack(
            'Nwidth/Nheight/CbitDepth/CcolorType/Ccompression/Cfilter/Cinterlace',
            $data,
        );

        return $header;
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
    private function assertSupportedPngHeader(array $header): void
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

    /**
     * @return list<string>
     */
    private function unfilterPngScanlines(string $idat, int $height, int $rowLength, int $bytesPerPixel): array
    {
        $inflated = $this->runWithoutWarnings(static fn (): string|false => gzuncompress($idat));
        if (!is_string($inflated)) {
            throw new InvalidArgumentException('PNG image data could not be decompressed.');
        }

        $rows = [];
        $offset = 0;
        $previousRow = str_repeat("\x00", $rowLength);

        for ($rowIndex = 0; $rowIndex < $height; $rowIndex++) {
            if (!isset($inflated[$offset])) {
                throw new InvalidArgumentException('PNG scanlines are truncated.');
            }

            $filterType = ord($inflated[$offset]);
            $offset++;
            $filteredRow = substr($inflated, $offset, $rowLength);
            if (strlen($filteredRow) !== $rowLength) {
                throw new InvalidArgumentException('PNG row data is truncated.');
            }

            $offset += $rowLength;
            $row = $this->unfilterPngRow($filterType, $filteredRow, $previousRow, $bytesPerPixel);
            $rows[] = $row;
            $previousRow = $row;
        }

        return $rows;
    }

    private function unfilterPngRow(
        int $filterType,
        string $filteredRow,
        string $previousRow,
        int $bytesPerPixel,
    ): string {
        $row = '';
        $rowLength = strlen($filteredRow);
        $paddedPreviousRow = str_repeat("\x00", $bytesPerPixel) . $previousRow;

        for ($index = 0; $index < $rowLength; $index++) {
            $rawByte = ord($filteredRow[$index]);
            $left = $index >= $bytesPerPixel ? ord($row[$index - $bytesPerPixel]) : 0;
            $above = ord($previousRow[$index]);
            $upperLeft = ord($paddedPreviousRow[$index]);

            $decodedByte = match ($filterType) {
                0 => $rawByte,
                1 => ($rawByte + $left) & 0xff,
                2 => ($rawByte + $above) & 0xff,
                3 => ($rawByte + intdiv($left + $above, 2)) & 0xff,
                4 => ($rawByte + $this->paethPredictor($left, $above, $upperLeft)) & 0xff,
                default => throw new InvalidArgumentException(
                    sprintf('Unsupported PNG row filter %d.', $filterType),
                ),
            };

            $row .= chr($decodedByte);
        }

        return $row;
    }

    private function paethPredictor(int $left, int $above, int $upperLeft): int
    {
        $prediction = $left + $above - $upperLeft;
        $leftDistance = abs($prediction - $left);
        $aboveDistance = abs($prediction - $above);
        $upperLeftDistance = abs($prediction - $upperLeft);

        $bestDistance = $leftDistance;
        $bestValue = $left;

        if ($aboveDistance < $bestDistance) {
            $bestDistance = $aboveDistance;
            $bestValue = $above;
        }

        if ($upperLeftDistance < $bestDistance) {
            return $upperLeft;
        }

        return $bestValue;
    }

    private function parseChunkLength(string $chunkLengthBytes): int
    {
        if (strlen($chunkLengthBytes) !== 4) {
            throw new InvalidArgumentException('Invalid PNG chunk length.');
        }

        return (ord($chunkLengthBytes[0]) << 24)
            | (ord($chunkLengthBytes[1]) << 16)
            | (ord($chunkLengthBytes[2]) << 8)
            | ord($chunkLengthBytes[3]);
    }

    private function assertNoPngChunksAfterIend(?int $iendOffset): void
    {
        if ($iendOffset !== null) {
            throw new InvalidArgumentException('PNG data after IEND is not supported.');
        }
    }

    private function assertPngEndsAtIend(int $iendOffset, int $contentLength): void
    {
        if ($iendOffset !== $contentLength) {
            throw new InvalidArgumentException('PNG data after IEND is not supported.');
        }
    }

    private function assertReadableSource(string $source): void
    {
        if (!is_file($source)) {
            throw new InvalidArgumentException(sprintf('Image source "%s" must be an existing file.', $source));
        }

        if (!is_readable($source)) {
            throw new InvalidArgumentException(sprintf('Image source "%s" must be readable.', $source));
        }
    }

    private function readSourceContents(string $source): string
    {
        $contents = $this->runWithoutWarnings(static fn (): string|false => file_get_contents($source));
        if (!is_string($contents)) {
            throw new InvalidArgumentException(sprintf('Failed to read image source "%s".', $source));
        }

        return $contents;
    }

    /**
     * @return array<int|string, mixed>
     */
    private function detectImageInfo(string $contents, string $source): array
    {
        $imageInfo = getimagesizefromstring($contents);
        if (!is_array($imageInfo)) {
            throw new InvalidArgumentException(sprintf('Unable to detect the image format for "%s".', $source));
        }

        return $imageInfo;
    }

    /**
     * @param array<int|string, mixed> $imageInfo
     */
    private function resolveMimeType(array $imageInfo, string $source): string
    {
        if (!array_key_exists('mime', $imageInfo)) {
            throw new InvalidArgumentException(sprintf(
                'Image metadata for "%s" does not expose a mime type.',
                $source,
            ));
        }

        $mime = $imageInfo['mime'];
        if (!is_string($mime)) {
            throw new InvalidArgumentException(sprintf(
                'Image metadata for "%s" must expose the mime type as a string.',
                $source,
            ));
        }

        return $mime;
    }

    private function resolveJpegColorSpace(mixed $channels): string
    {
        return match ($channels) {
            1 => '/DeviceGray',
            4 => '/DeviceCMYK',
            default => '/DeviceRGB',
        };
    }

    private function runWithoutWarnings(callable $operation): mixed
    {
        set_error_handler(static fn (): bool => true);

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }
}
