<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf;

use InvalidArgumentException;

/** @internal Bounds-checked unsigned sfnt word reader. */
final readonly class SfntTableReader
{
    public function __construct(private string $bytes)
    {
    }

    public function length(): int
    {
        return strlen($this->bytes);
    }

    public function u16(int $offset): int
    {
        $this->requireRange($offset, 2);
        /** @var array<int, int> $word */
        $word = unpack('n', $this->bytes, $offset);
        return $word[1];
    }

    public function s16(int $offset): int
    {
        $value = $this->u16($offset);
        return $value > 32767 ? $value - 65536 : $value;
    }

    public function u32(int $offset): int
    {
        $this->requireRange($offset, 4);
        return (ord($this->bytes[$offset]) << 24)
            | (ord($this->bytes[$offset + 1]) << 16)
            | (ord($this->bytes[$offset + 2]) << 8)
            | ord($this->bytes[$offset + 3]);
    }

    public function requireRange(int $offset, int $size): void
    {
        if ($offset < 0 || $size < 0 || $offset > $this->length() || $size > $this->length() - $offset) {
            throw new InvalidArgumentException('TrueType read outside font bounds.');
        }
    }
}
