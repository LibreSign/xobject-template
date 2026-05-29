<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf\Png;

/** @internal */
interface PngScanlineCompressorInterface
{
    public function compress(string $scanlines): string|false;
}
