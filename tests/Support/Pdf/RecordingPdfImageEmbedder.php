<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Support\Pdf;

use LibreSign\XObjectTemplate\Pdf\EmbeddedPdfImage;
use LibreSign\XObjectTemplate\Pdf\PdfImageEmbedderInterface;

final class RecordingPdfImageEmbedder implements PdfImageEmbedderInterface
{
    /** @var list<string> */
    public array $sources = [];

    /**
     * @param array<string, mixed> $dictionary
     */
    public function __construct(
        private readonly array $dictionary,
        private readonly string $stream,
    ) {
    }

    public function embed(string $source): EmbeddedPdfImage
    {
        $this->sources[] = $source;

        return new EmbeddedPdfImage(
            dictionary: $this->dictionary,
            stream: $this->stream,
        );
    }
}
