<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf;

/** @internal */
interface ImageMetadataInspectorInterface
{
    /**
     * @return array<int|string, mixed>
     */
    public function detect(string $contents, string $source): array;

    /**
     * @param array<int|string, mixed> $imageInfo
     */
    public function resolveMimeType(array $imageInfo, string $source): string;
}
