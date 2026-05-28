<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf;

use InvalidArgumentException;

final readonly class FilesystemPdfImageEmbedder implements PdfImageEmbedderInterface
{
    public function embed(string $source): EmbeddedPdfImage
    {
        if (!is_file($source) || !is_readable($source)) {
            throw new InvalidArgumentException(sprintf('Image source "%s" must be a readable file.', $source));
        }

        $contents = file_get_contents($source);
        if ($contents === false) {
            throw new InvalidArgumentException(sprintf('Failed to read image source "%s".', $source));
        }

        $imageInfo = getimagesizefromstring($contents);
        if ($imageInfo === false || !isset($imageInfo['mime'])) {
            throw new InvalidArgumentException(sprintf('Unable to detect the image format for "%s".', $source));
        }

        $mime = $imageInfo['mime'];

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
        if (!is_int($width) || !is_int($height)) {
            throw new InvalidArgumentException('JPEG metadata must expose width and height.');
        }

        $channels = $imageInfo['channels'] ?? 3;
        if (!is_int($channels)) {
            $channels = 3;
        }

        $colorSpace = match ($channels) {
            1 => '/DeviceGray',
            4 => '/DeviceCMYK',
            default => '/DeviceRGB',
        };

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

        $offset = 8;
        $header = null;
        $idat = '';

        while ($offset + 8 <= strlen($contents)) {
            ['data' => $data, 'type' => $type] = $this->readPngChunk($contents, $offset);

            if ($type === 'IHDR') {
                $header = $this->parsePngHeader($data);
            }

            if ($type === 'IDAT') {
                $idat .= $data;
            }

            if ($type === 'IEND') {
                break;
            }
        }

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
        $chunkLength = unpack('Nvalue', substr($contents, $offset, 4));
        $offset += 4;
        $type = substr($contents, $offset, 4);
        $offset += 4;

        if ($chunkLength === false || !isset($chunkLength['value'])) {
            throw new InvalidArgumentException('Invalid PNG chunk length.');
        }

        $data = substr($contents, $offset, $chunkLength['value']);
        if (strlen($data) !== $chunkLength['value']) {
            throw new InvalidArgumentException('PNG chunk data is truncated.');
        }

        $offset += $chunkLength['value'] + 4;

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
        $header = unpack(
            'Nwidth/Nheight/CbitDepth/CcolorType/Ccompression/Cfilter/Cinterlace',
            $data,
        );
        if ($header === false) {
            throw new InvalidArgumentException('Unable to parse the PNG IHDR chunk.');
        }

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
        $inflated = gzuncompress($idat);
        if ($inflated === false) {
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

        for ($index = 0; $index < $rowLength; $index++) {
            $rawByte = ord($filteredRow[$index]);
            $left = $index >= $bytesPerPixel ? ord($row[$index - $bytesPerPixel]) : 0;
            $above = ord($previousRow[$index]);
            $upperLeft = $index >= $bytesPerPixel ? ord($previousRow[$index - $bytesPerPixel]) : 0;

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

        if ($leftDistance <= $aboveDistance && $leftDistance <= $upperLeftDistance) {
            return $left;
        }

        if ($aboveDistance <= $upperLeftDistance) {
            return $above;
        }

        return $upperLeft;
    }
}
