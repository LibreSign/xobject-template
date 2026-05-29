<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf\Png;

/** @internal */
final class PhpPngHeaderUnpacker implements PngHeaderUnpackerInterface
{
    public function unpack(string $data): array|false
    {
        return unpack(
            'Nwidth/Nheight/CbitDepth/CcolorType/Ccompression/Cfilter/Cinterlace',
            $data,
        );
    }
}
