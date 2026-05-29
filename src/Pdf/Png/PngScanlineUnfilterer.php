<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf\Png;

use InvalidArgumentException;
use LibreSign\XObjectTemplate\Pdf\PhpWarningToExceptionConverter;
use LibreSign\XObjectTemplate\Pdf\WarningToExceptionConverterInterface;
use LibreSign\XObjectTemplate\Pdf\Png\PngScanlineUnfiltererInterface;

/** @internal */
final readonly class PngScanlineUnfilterer implements PngScanlineUnfiltererInterface
{
    private WarningToExceptionConverterInterface $warningConverter;

    public function __construct(?WarningToExceptionConverterInterface $warningConverter = null)
    {
        $this->warningConverter = $warningConverter ?? new PhpWarningToExceptionConverter();
    }

    /**
     * @return list<string>
     */
    public function unfilter(string $idat, int $height, int $rowLength, int $bytesPerPixel): array
    {
        $inflated = $this->warningConverter->run(
            static fn (): string|false => gzuncompress($idat),
            'PNG image data could not be decompressed.',
        );
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
            $row = $this->unfilterRow($filterType, $filteredRow, $previousRow, $bytesPerPixel);
            $rows[] = $row;
            $previousRow = $row;
        }

        return $rows;
    }

    public function unfilterRow(
        int $filterType,
        string $filteredRow,
        string $previousRow,
        int $bytesPerPixel,
    ): string {
        $row = '';
        $paddedPreviousRow = str_repeat("\x00", $bytesPerPixel) . $previousRow;

        foreach (str_split($filteredRow) as $index => $rawByteCharacter) {
            $rawByte = ord($rawByteCharacter);
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

    public function paethPredictor(int $left, int $above, int $upperLeft): int
    {
        $prediction = $left + $above - $upperLeft;
        $leftDistance = abs($prediction - $left);
        $aboveDistance = abs($prediction - $above);
        $upperLeftDistance = abs($prediction - $upperLeft);

        $bestDistance = min($leftDistance, $aboveDistance, $upperLeftDistance);

        if ($bestDistance === $leftDistance) {
            return $left;
        }

        if ($bestDistance === $aboveDistance) {
            return $above;
        }

        return $upperLeft;
    }
}
