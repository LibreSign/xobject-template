<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf;

use InvalidArgumentException;

/** @internal Unicode TrueType cmap format 4/12 lookup. */
final readonly class TrueTypeCmap
{
    private int $offset;
    private int $format;
    private int $length;

    public function __construct(private SfntTableReader $reader, int $cmapOffset, int $tableLength)
    {
        $reader->requireRange($cmapOffset, $tableLength);
        $this->offset = $this->chooseCmapOffset($cmapOffset, $tableLength);
        $this->format = $reader->u16($this->offset);
        $this->length = $this->format === 12
            ? $reader->u32($this->offset + 4)
            : $reader->u16($this->offset + 2);
        $reader->requireRange($this->offset, $this->length);
        if ($this->length > $cmapOffset + $tableLength - $this->offset) {
            throw new InvalidArgumentException('TrueType cmap subtable exceeds its table bounds.');
        }
        $this->validateIndex();
    }

    public function glyphFor(int $codepoint): int
    {
        if ($codepoint < 0 || $codepoint > 0x10FFFF) {
            return 0;
        }
        return $this->format === 12
            ? $this->glyphFromGroups($codepoint)
            : $this->glyphFromSegments($codepoint);
    }

    private function chooseCmapOffset(int $tableOffset, int $tableLength): int
    {
        $reader = $this->reader;
        $records = $reader->u16($tableOffset + 2);
        if ($records > 256 || $records * 8 > $tableLength - 4) {
            throw new InvalidArgumentException('TrueType Unicode cmap contains too many encoding records.');
        }
        $reader->requireRange($tableOffset + 4, $records * 8);
        $bestPriority = -1;
        $selectedOffset = -1;
        for ($index = 0; $index < $records; $index++) {
            $record = $tableOffset + 4 + 8 * $index;
            $platform = $reader->u16($record);
            $encoding = $reader->u16($record + 2);
            $offset = $tableOffset + $reader->u32($record + 4);
            if ($offset < $tableOffset || $offset > $tableOffset + $tableLength - 2) {
                continue;
            }
            $format = $reader->u16($offset);
            $priority = $this->priority($platform, $encoding, $format);
            if ($priority > $bestPriority) {
                $bestPriority = $priority;
                $selectedOffset = $offset;
            }
        }
        if ($selectedOffset === -1) {
            throw new InvalidArgumentException('TrueType font has no Unicode cmap.');
        }
        return $selectedOffset;
    }

    private function priority(int $platform, int $encoding, int $format): int
    {
        if ($format === 12) {
            return $platform === 3 && $encoding === 10 ? 4 : ($platform === 0 ? 3 : -1);
        }
        if ($format === 4) {
            return $platform === 3 && $encoding === 1 ? 2 : ($platform === 0 ? 1 : -1);
        }
        return -1;
    }

    private function validateIndex(): void
    {
        if ($this->format === 12) {
            $groups = $this->reader->u32($this->offset + 12);
            if ($groups > intdiv($this->length - 16, 12)) {
                throw new InvalidArgumentException('Truncated TrueType format 12 cmap.');
            }
            return;
        }
        $segments = intdiv($this->reader->u16($this->offset + 6), 2);
        if ($segments <= 0 || 16 + $segments * 8 > $this->length) {
            throw new InvalidArgumentException('Truncated TrueType format 4 cmap.');
        }
    }

    private function glyphFromGroups(int $codepoint): int
    {
        $groups = $this->reader->u32($this->offset + 12);
        $low = 0;
        $high = $groups - 1;
        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            $group = $this->offset + 16 + $middle * 12;
            $start = $this->reader->u32($group);
            $end = $this->reader->u32($group + 4);
            if ($codepoint < $start) {
                $high = $middle - 1;
                continue;
            }
            if ($codepoint > $end) {
                $low = $middle + 1;
                continue;
            }
            return $this->reader->u32($group + 8) + $codepoint - $start;
        }
        return 0;
    }

    private function glyphFromSegments(int $codepoint): int
    {
        if ($codepoint > 0xFFFF) {
            return 0;
        }
        $segments = intdiv($this->reader->u16($this->offset + 6), 2);
        $endOffset = $this->offset + 14;
        $startOffset = $endOffset + 2 * $segments + 2;
        $deltaOffset = $startOffset + 2 * $segments;
        $rangeOffset = $deltaOffset + 2 * $segments;
        for ($index = 0; $index < $segments; $index++) {
            if ($codepoint > $this->reader->u16($endOffset + 2 * $index)) {
                continue;
            }
            if ($codepoint < $this->reader->u16($startOffset + 2 * $index)) {
                return 0;
            }
            return $this->segmentGlyph($codepoint, $index, $startOffset, $deltaOffset, $rangeOffset);
        }
        return 0;
    }

    private function segmentGlyph(
        int $codepoint,
        int $index,
        int $startOffset,
        int $deltaOffset,
        int $rangeOffset,
    ): int {
        $delta = $this->reader->u16($deltaOffset + 2 * $index);
        $range = $this->reader->u16($rangeOffset + 2 * $index);
        if ($range === 0) {
            return ($codepoint + $delta) & 0xFFFF;
        }
        $glyphOffset = $rangeOffset + 2 * $index + $range
            + 2 * ($codepoint - $this->reader->u16($startOffset + 2 * $index));
        if ($glyphOffset < $this->offset || $glyphOffset + 2 > $this->offset + $this->length) {
            throw new InvalidArgumentException('TrueType cmap glyph index exceeds subtable.');
        }
        $glyph = $this->reader->u16($glyphOffset);
        return $glyph === 0 ? 0 : ($glyph + $delta) & 0xFFFF;
    }
}
