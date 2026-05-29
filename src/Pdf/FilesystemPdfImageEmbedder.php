<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf;

use InvalidArgumentException;

final readonly class FilesystemPdfImageEmbedder implements PdfImageEmbedderInterface
{
    private FilesystemImageSourceReaderInterface $sourceReader;
    private ImageMetadataInspectorInterface $metadataInspector;
    private JpegPdfImageFactoryInterface $jpegImageFactory;
    private PngPdfImageFactoryInterface $pngImageFactory;

    public function __construct(
        ?FilesystemImageSourceReaderInterface $sourceReader = null,
        ?ImageMetadataInspectorInterface $metadataInspector = null,
        ?JpegPdfImageFactoryInterface $jpegImageFactory = null,
        ?PngPdfImageFactoryInterface $pngImageFactory = null,
    ) {
        $this->sourceReader = $sourceReader ?? new FilesystemImageSourceReader();
        $this->metadataInspector = $metadataInspector ?? new ImageMetadataInspector();
        $this->jpegImageFactory = $jpegImageFactory ?? new JpegPdfImageFactory();
        $this->pngImageFactory = $pngImageFactory ?? new PngPdfImageFactory();
    }

    public function embed(string $source): EmbeddedPdfImage
    {
        $contents = $this->sourceReader->read($source);
        $imageInfo = $this->metadataInspector->detect($contents, $source);
        $mime = $this->metadataInspector->resolveMimeType($imageInfo, $source);

        return match ($mime) {
            'image/jpeg' => $this->jpegImageFactory->create($contents, $imageInfo),
            'image/png' => $this->pngImageFactory->create($contents),
            default => throw new InvalidArgumentException(
                sprintf('Unsupported image format "%s".', $mime),
            ),
        };
    }
}
