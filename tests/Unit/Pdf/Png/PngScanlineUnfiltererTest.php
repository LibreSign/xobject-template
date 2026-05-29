<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf\Png;

use LibreSign\XObjectTemplate\Pdf\Png\PngScanlineUnfilterer;
use LibreSign\XObjectTemplate\Pdf\WarningToExceptionConverterInterface;
use PHPUnit\Framework\TestCase;

final class PngScanlineUnfiltererTest extends TestCase
{
    public function testUnfilterRejectsInvalidCompressedData(): void
    {
        $unfilterer = new PngScanlineUnfilterer();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PNG image data could not be decompressed.');

        $unfilterer->unfilter('not-compressed', 1, 1, 1);
    }

    public function testUnfilterUsesInjectedWarningConverterResult(): void
    {
        $unfilterer = new PngScanlineUnfilterer(new class implements WarningToExceptionConverterInterface {
            public function run(callable $operation, string $message): mixed
            {
                return "\x00\x7f";
            }
        });

        self::assertSame(["\x7f"], $unfilterer->unfilter('ignored-idat', 1, 1, 1));
    }

    public function testUnfilterRejectsMissingRowFilterBytes(): void
    {
        $unfilterer = new PngScanlineUnfilterer();
        $compressed = gzcompress('');
        if (!is_string($compressed)) {
            self::fail('Failed to compress empty scanlines fixture.');
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PNG scanlines are truncated.');

        $unfilterer->unfilter($compressed, 1, 1, 1);
    }

    public function testUnfilterRejectsMissingRowPayloadBytes(): void
    {
        $unfilterer = new PngScanlineUnfilterer();
        $compressed = gzcompress("\x00");
        if (!is_string($compressed)) {
            self::fail('Failed to compress truncated row scanlines fixture.');
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PNG row data is truncated.');

        $unfilterer->unfilter($compressed, 1, 1, 1);
    }

    public function testUnfilterRowSupportsSubFilterWithMultiPixelContext(): void
    {
        $unfilterer = new PngScanlineUnfilterer();

        $decodedRow = $unfilterer->unfilterRow(
            1,
            "\x05\x06\x02\x03",
            "\x00\x00\x00\x00",
            2,
        );

        self::assertSame("\x05\x06\x07\x09", $decodedRow);
    }

    public function testUnfilterRowSupportsUpFilterWithPriorRowContext(): void
    {
        $unfilterer = new PngScanlineUnfilterer();

        $decodedRow = $unfilterer->unfilterRow(
            2,
            "\x05\x06\x07\x08",
            "\x01\x02\x03\x04",
            2,
        );

        self::assertSame("\x06\x08\x0a\x0c", $decodedRow);
    }

    public function testUnfilterRowSupportsAverageFilterWithMultiPixelContext(): void
    {
        $unfilterer = new PngScanlineUnfilterer();

        $decodedRow = $unfilterer->unfilterRow(
            3,
            "\x55\x5a\x5f\x64\x3c\x3c\x3c\x3c",
            "\x0a\x14\x1e\x28\x32\x3c\x46\x50",
            4,
        );

        self::assertSame("\x5a\x64\x6e\x78\x82\x8c\x96\xa0", $decodedRow);
    }

    public function testUnfilterRowSupportsPaethFilterWithMultiPixelContext(): void
    {
        $unfilterer = new PngScanlineUnfilterer();

        $decodedRow = $unfilterer->unfilterRow(
            4,
            "\x0a\x0a\x0a\x0a\x0a\x14\x1e\x28",
            "\x0a\x14\x1e\x28\x3c\x46\x50\x5a",
            4,
        );

        self::assertSame("\x14\x1e\x28\x32\x46\x5a\x6e\x82", $decodedRow);
    }

    public function testUnfilterRowUsesPreviousUpperLeftAtBoundary(): void
    {
        $unfilterer = new PngScanlineUnfilterer();

        $decodedRow = $unfilterer->unfilterRow(
            4,
            "\xff\x00",
            "\x01\x01",
            1,
        );

        self::assertSame('0000', bin2hex($decodedRow));
    }

    public function testUnfilterRowUsesZeroUpperLeftFallbackForFirstByte(): void
    {
        $unfilterer = new PngScanlineUnfilterer();

        $decodedRow = $unfilterer->unfilterRow(
            4,
            "\x00",
            "\x01",
            1,
        );

        self::assertSame("\x01", $decodedRow);
    }

    public function testPaethPredictorSelectsExpectedNeighborAcrossTieCases(): void
    {
        $unfilterer = new PngScanlineUnfilterer();

        self::assertSame(20, $unfilterer->paethPredictor(20, 20, 10));
        self::assertSame(50, $unfilterer->paethPredictor(50, 10, 20));
        self::assertSame(50, $unfilterer->paethPredictor(10, 50, 20));
        self::assertSame(20, $unfilterer->paethPredictor(10, 30, 20));
        self::assertSame(0, $unfilterer->paethPredictor(0, 3, 2));
        self::assertSame(3, $unfilterer->paethPredictor(0, 3, 1));
    }

    public function testPaethPredictorMatchesReferenceImplementationAcrossRepresentativeRange(): void
    {
        $unfilterer = new PngScanlineUnfilterer();

        for ($left = 0; $left <= 15; $left++) {
            for ($above = 0; $above <= 15; $above++) {
                for ($upperLeft = 0; $upperLeft <= 15; $upperLeft++) {
                    self::assertSame(
                        $this->referencePaethPredictor($left, $above, $upperLeft),
                        $unfilterer->paethPredictor($left, $above, $upperLeft),
                        sprintf(
                            'Failed for left=%d, above=%d, upperLeft=%d.',
                            $left,
                            $above,
                            $upperLeft,
                        ),
                    );
                }
            }
        }
    }

    private function referencePaethPredictor(int $left, int $above, int $upperLeft): int
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
