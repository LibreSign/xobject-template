<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf;

use InvalidArgumentException;

/** @internal */
final readonly class ImageMetadataInspector implements ImageMetadataInspectorInterface
{
    /**
     * @return array<int|string, mixed>
     */
    public function detect(string $contents, string $source): array
    {
        $imageInfo = getimagesizefromstring($contents);
        if (!is_array($imageInfo)) {
            throw new InvalidArgumentException(sprintf('Unable to detect the image format for "%s".', $source));
        }

        return $imageInfo;
    }

    /**
     * @param array<int|string, mixed> $imageInfo
     */
    public function resolveMimeType(array $imageInfo, string $source): string
    {
        if (!array_key_exists('mime', $imageInfo)) {
            throw new InvalidArgumentException(sprintf(
                'Image metadata for "%s" does not expose a mime type.',
                $source,
            ));
        }

        $mime = $imageInfo['mime'];
        if (!is_string($mime)) {
            throw new InvalidArgumentException(sprintf(
                'Image metadata for "%s" must expose the mime type as a string.',
                $source,
            ));
        }

        return $mime;
    }
}
