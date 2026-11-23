<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf;

// Stream wrapper method names are prescribed by PHP and cannot be camelCase.
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

/** @internal A deterministic short read after an apparently valid file stat. */
final class TruncatedFontStream
{
    public mixed $context;
    private int $position = 0;

    /** @return array{mode: int, size: int} */
    public function url_stat(string $path, int $flags): array
    {
        return ['mode' => 0100644, 'size' => 128];
    }

    /** @return array{mode: int, size: int} */
    public function stream_stat(): array
    {
        return $this->url_stat('', 0);
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        $bytes = str_repeat('X', max(0, min($count, 64 - $this->position)));
        $this->position += strlen($bytes);
        return $bytes;
    }

    public function stream_eof(): bool
    {
        return $this->position >= 64;
    }

    public function stream_tell(): int
    {
        return $this->position;
    }
}
