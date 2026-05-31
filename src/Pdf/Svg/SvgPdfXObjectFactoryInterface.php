<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf\Svg;

use LibreSign\XObjectTemplate\Pdf\EmbeddedPdfImage;

interface SvgPdfXObjectFactoryInterface
{
    public function create(string $svgContents, string $source): EmbeddedPdfImage;
}
