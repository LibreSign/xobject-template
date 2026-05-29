<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Support;

use RuntimeException;

trait UsesTemporaryFiles
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDownTemporaryFiles(): void
    {
        foreach ($this->temporaryFiles as $temporaryFile) {
            if (is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }

        $this->temporaryFiles = [];
    }

    protected function createTemporaryFile(string $extension, string $contents): string
    {
        $temporaryFile = tempnam(sys_get_temp_dir(), 'xot_');
        if ($temporaryFile === false) {
            throw new RuntimeException('Failed to create a temporary file for image export tests.');
        }

        $pathWithExtension = $temporaryFile . '.' . $extension;
        if (rename($temporaryFile, $pathWithExtension) === false) {
            throw new RuntimeException('Failed to rename the temporary image fixture.');
        }

        if (file_put_contents($pathWithExtension, $contents) === false) {
            throw new RuntimeException('Failed to write the temporary image fixture.');
        }

        $this->temporaryFiles[] = $pathWithExtension;

        return $pathWithExtension;
    }
}
