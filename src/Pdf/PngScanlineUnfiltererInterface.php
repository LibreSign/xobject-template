<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf;

/** @internal */
interface PngScanlineUnfiltererInterface
{
    /**
     * @return list<string>
     */
    public function unfilter(string $idat, int $height, int $rowLength, int $bytesPerPixel): array;
}
