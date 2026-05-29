<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf\Jpeg;

use LibreSign\XObjectTemplate\Pdf\EmbeddedPdfImage;

/** @internal */
interface JpegPdfImageFactoryInterface
{
    /**
     * @param array<int|string, mixed> $imageInfo
     */
    public function create(string $contents, array $imageInfo): EmbeddedPdfImage;
}
