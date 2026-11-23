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
    /** @var list<array{start: int, end: int, glyph: int}> */
    private array $groups;

    public function __construct(private SfntTableReader $reader, int $cmapOffset, int $tableLength)
    {
        if ($tableLength < 4) {
            throw new InvalidArgumentException('Truncated TrueType cmap table.');
        }
        $reader->requireRange($cmapOffset, $tableLength);
        $this->offset = $this->chooseCmapOffset($cmapOffset, $tableLength);
        $this->format = $reader->u16($this->offset);
        $this->length = $this->format === 12
            ? $reader->u32($this->offset + 4)
            : $reader->u16($this->offset + 2);
        if ($this->length > $cmapOffset + $tableLength - $this->offset) {
            throw new InvalidArgumentException('TrueType cmap subtable exceeds its table bounds.');
        }
        $this->groups = $this->format === 12 ? $this->readGroups() : [];
        if ($this->format !== 12) {
            $this->validateSegments();
        }
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
        $records = $this->reader->u16($tableOffset + 2);
        if ($records > 256 || $records * 8 > $tableLength - 4) {
            throw new InvalidArgumentException('TrueType Unicode cmap contains too many encoding records.');
        }
        /** @var list<array{platform: int, encoding: int, format: int, offset: int}> $entries */
        $entries = [];
        for ($index = 0; $index < $records; $index++) {
            $record = $tableOffset + 4 + 8 * $index;
            $offset = $tableOffset + $this->reader->u32($record + 4);
            if ($offset > $tableOffset + $tableLength - 2) {
                continue;
            }
            $entries[] = [
                'platform' => $this->reader->u16($record),
                'encoding' => $this->reader->u16($record + 2),
                'format' => $this->reader->u16($offset),
                'offset' => $offset,
            ];
        }
        // Preference order: Windows UCS-4, Unicode UCS-4, Windows BMP, Unicode BMP.
        foreach ([[3, 10, 12], [0, null, 12], [3, 1, 4], [0, null, 4]] as [$platform, $encoding, $format]) {
            foreach ($entries as $entry) {
                if ($this->matchesPreference($entry, $platform, $encoding, $format)) {
                    return $entry['offset'];
                }
            }
        }
        throw new InvalidArgumentException('TrueType font has no Unicode cmap.');
    }

    /**
     * @param array{platform: int, encoding: int, format: int, offset: int} $entry
     */
    private function matchesPreference(array $entry, int $platform, ?int $encoding, int $format): bool
    {
        return $entry['platform'] === $platform
            && ($encoding === null || $entry['encoding'] === $encoding)
            && $entry['format'] === $format;
    }

    /** @return list<array{start: int, end: int, glyph: int}> */
    private function readGroups(): array
    {
        $groupCount = $this->reader->u32($this->offset + 12);
        if ($groupCount > intdiv($this->length - 16, 12)) {
            throw new InvalidArgumentException('Truncated TrueType format 12 cmap.');
        }
        if ($groupCount > 16384) {
            throw new InvalidArgumentException('TrueType format 12 cmap exceeds safe group limit.');
        }
        $groups = [];
        $lastEnd = null;
        for ($index = 0; $index < $groupCount; $index++) {
            $groupOffset = $this->offset + 16 + $index * 12;
            $start = $this->reader->u32($groupOffset);
            $end = $this->reader->u32($groupOffset + 4);
            $glyph = $this->reader->u32($groupOffset + 8);
            if ($start > $end || $end > 0x10FFFF || ($lastEnd !== null && $start <= $lastEnd)) {
                throw new InvalidArgumentException('Invalid TrueType format 12 cmap ranges.');
            }
            $groups[] = ['start' => $start, 'end' => $end, 'glyph' => $glyph];
            $lastEnd = $end;
        }
        return $groups;
    }

    private function validateSegments(): void
    {
        $segments = intdiv($this->reader->u16($this->offset + 6), 2);
        if ($segments === 0 || 16 + $segments * 8 > $this->length) {
            throw new InvalidArgumentException('Truncated TrueType format 4 cmap.');
        }
    }

    private function glyphFromGroups(int $codepoint): int
    {
        foreach ($this->groups as $group) {
            if ($codepoint < $group['start']) {
                return 0;
            }
            if ($codepoint <= $group['end']) {
                return $group['glyph'] + $codepoint - $group['start'];
            }
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
        $endCursor = $endOffset;
        $startCursor = $startOffset;
        $deltaCursor = $deltaOffset;
        $rangeCursor = $rangeOffset;
        $endLimit = $endOffset + 2 * $segments;
        while ($endCursor < $endLimit) {
            if ($codepoint <= $this->reader->u16($endCursor)) {
                if ($codepoint < $this->reader->u16($startCursor)) {
                    return 0;
                }
                return $this->segmentGlyph($codepoint, $startCursor, $deltaCursor, $rangeCursor);
            }
            $endCursor += 2;
            $startCursor += 2;
            $deltaCursor += 2;
            $rangeCursor += 2;
        }
        return 0;
    }

    private function segmentGlyph(
        int $codepoint,
        int $startCursor,
        int $deltaCursor,
        int $rangeCursor,
    ): int {
        $delta = $this->reader->u16($deltaCursor);
        $range = $this->reader->u16($rangeCursor);
        if ($range === 0) {
            return ($codepoint + $delta) & 0xFFFF;
        }
        $glyphOffset = $rangeCursor + $range
            + 2 * ($codepoint - $this->reader->u16($startCursor));
        if ($glyphOffset + 2 > $this->offset + $this->length) {
            throw new InvalidArgumentException('TrueType cmap glyph index exceeds subtable.');
        }
        $glyph = $this->reader->u16($glyphOffset);
        return $glyph === 0 ? 0 : ($glyph + $delta) & 0xFFFF;
    }
}
