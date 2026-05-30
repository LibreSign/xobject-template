<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf\Png;

/** @internal */
final class PhpPngHeaderUnpacker implements PngHeaderUnpackerInterface
{
    private const HEADER_BYTES = 13;

    public function unpack(string $data): array|false
    {
        if (strlen($data) < self::HEADER_BYTES) {
            return false;
        }

        /** @var array{width: int, height: int, bitDepth: int, colorType: int, compression: int, filter: int, interlace: int} $header */
        $header = unpack(
            'Nwidth/Nheight/CbitDepth/CcolorType/Ccompression/Cfilter/Cinterlace',
            $data,
        );

        return $header;
    }
}
