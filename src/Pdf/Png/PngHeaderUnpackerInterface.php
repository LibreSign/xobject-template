<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf\Png;

/** @internal */
interface PngHeaderUnpackerInterface
{
    /**
     * @return array{
     *     width: int,
     *     height: int,
     *     bitDepth: int,
     *     colorType: int,
     *     compression: int,
     *     filter: int,
     *     interlace: int
     * }|false
     */
    public function unpack(string $data): array|false;
}
