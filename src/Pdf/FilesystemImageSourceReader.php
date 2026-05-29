<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf;

use InvalidArgumentException;

/** @internal */
final readonly class FilesystemImageSourceReader implements FilesystemImageSourceReaderInterface
{
    private WarningToExceptionConverterInterface $warningConverter;

    public function __construct(?WarningToExceptionConverterInterface $warningConverter = null)
    {
        $this->warningConverter = $warningConverter ?? new PhpWarningToExceptionConverter();
    }

    public function read(string $source): string
    {
        $this->assertReadableSource($source);

        $contents = $this->warningConverter->run(
            static fn (): string|false => file_get_contents($source),
            sprintf('Failed to read image source "%s".', $source),
        );
        if (!is_string($contents)) {
            throw new InvalidArgumentException(sprintf('Failed to read image source "%s".', $source));
        }

        return $contents;
    }

    private function assertReadableSource(string $source): void
    {
        if (!is_file($source)) {
            throw new InvalidArgumentException(sprintf('Image source "%s" must be an existing file.', $source));
        }

        if (!is_readable($source)) {
            throw new InvalidArgumentException(sprintf('Image source "%s" must be readable.', $source));
        }
    }
}
