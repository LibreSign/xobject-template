<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf;

use Closure;
use InvalidArgumentException;

/**
 * Temporary, private copy of an immutable font for a native shaping process.
 *
 * @internal
 */
final readonly class TemporaryFontFile
{
    /** @var Closure */
    private Closure $create;
    /** @var Closure */
    private Closure $write;

    /**
     * @param ?Closure $create
     * @param ?Closure $write
     */
    public function __construct(?Closure $create = null, ?Closure $write = null)
    {
        $this->create = $create ?? static fn (): string|false =>
            tempnam(sys_get_temp_dir(), 'libresign-harfbuzz-font-');
        $this->write = $write ?? static fn (string $path, string $bytes): int|false =>
            file_put_contents($path, $bytes);
    }

    /**
     * @template T
     * @param Closure(string):T $callback
     * @return T
     */
    public function withFont(string $bytes, Closure $callback): mixed
    {
        /** @var string|false $path */
        $path = ($this->create)();
        if ($path === false) {
            throw new InvalidArgumentException('Unable to prepare temporary font data for shaping.');
        }
        try {
            /** @var int|false $written */
            $written = ($this->write)($path, $bytes);
            if ($written !== strlen($bytes)) {
                throw new InvalidArgumentException('Unable to write temporary font data for shaping.');
            }
            return $callback($path);
        } finally {
            unlink($path);
        }
    }
}
