<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf\Jpeg;

use InvalidArgumentException;
use LibreSign\XObjectTemplate\Pdf\EmbeddedPdfImage;
use LibreSign\XObjectTemplate\Pdf\Jpeg\JpegPdfImageFactoryInterface;

/** @internal */
final readonly class JpegPdfImageFactory implements JpegPdfImageFactoryInterface
{
    /**
     * @param array<int|string, mixed> $imageInfo
     */
    public function create(string $contents, array $imageInfo): EmbeddedPdfImage
    {
        $width = $imageInfo[0] ?? null;
        $height = $imageInfo[1] ?? null;

        if (!is_int($width) || !is_int($height)) {
            throw new InvalidArgumentException('JPEG metadata must expose width and height.');
        }

        return new EmbeddedPdfImage(
            dictionary: [
                'Type' => '/XObject',
                'Subtype' => '/Image',
                'Width' => $width,
                'Height' => $height,
                'ColorSpace' => $this->resolveColorSpace($imageInfo['channels'] ?? null),
                'BitsPerComponent' => 8,
                'Filter' => '/DCTDecode',
            ],
            stream: $contents,
        );
    }

    private function resolveColorSpace(mixed $channels): string
    {
        return match ($channels) {
            1 => '/DeviceGray',
            4 => '/DeviceCMYK',
            default => '/DeviceRGB',
        };
    }
}
