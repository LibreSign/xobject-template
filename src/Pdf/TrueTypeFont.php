<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf;

use InvalidArgumentException;

/**
 * Reads the sfnt tables required to embed a TrueType font as a PDF CIDFontType2.
 * The caller supplies an embeddable .ttf whose license permits PDF embedding.
 */
final class TrueTypeFont
{
    private string $bytes;
    /** @var array<string, array{offset: int, length: int}> */
    private array $tables = [];
    private int $cmapOffset;
    private int $cmapFormat;
    private int $unitsPerEm;
    private int $hMetricsCount;
    private int $glyphCount;
    private int $ascent;
    private int $descent;
    private int $bboxLeft;
    private int $bboxBottom;
    private int $bboxRight;
    private int $bboxTop;

    public function __construct(string $path)
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false || strlen($bytes) < 64) {
            throw new InvalidArgumentException('TrueType font file cannot be read.');
        }
        $this->bytes = $bytes;
        if ($this->u32(0) !== 0x00010000) {
            throw new InvalidArgumentException('Only sfnt TrueType outlines are supported (not OTF/CFF or TTC).');
        }
        $count = $this->u16(4);
        if ($count > 128 || 12 + $count * 16 > strlen($bytes)) {
            throw new InvalidArgumentException('Invalid TrueType table directory.');
        }
        for ($i = 0; $i < $count; $i++) {
            $at = 12 + $i * 16;
            $name = substr($bytes, $at, 4);
            $offset = $this->u32($at + 8);
            $length = $this->u32($at + 12);
            if ($offset + $length > strlen($bytes)) {
                throw new InvalidArgumentException('TrueType table exceeds font bounds.');
            }
            $this->tables[$name] = ['offset' => $offset, 'length' => $length];
        }
        foreach (['head', 'hhea', 'hmtx', 'maxp', 'cmap'] as $required) {
            if (!isset($this->tables[$required])) {
                throw new InvalidArgumentException('Missing TrueType table: ' . $required);
            }
        }
        $this->unitsPerEm = $this->u16($this->table('head') + 18);
        $this->bboxLeft = $this->s16($this->table('head') + 36);
        $this->bboxBottom = $this->s16($this->table('head') + 38);
        $this->bboxRight = $this->s16($this->table('head') + 40);
        $this->bboxTop = $this->s16($this->table('head') + 42);
        $this->ascent = $this->s16($this->table('hhea') + 4);
        $this->descent = $this->s16($this->table('hhea') + 6);
        $this->hMetricsCount = $this->u16($this->table('hhea') + 34);
        $this->glyphCount = $this->u16($this->table('maxp') + 4);
        if ($this->unitsPerEm === 0 || $this->glyphCount === 0 || $this->hMetricsCount === 0) {
            throw new InvalidArgumentException('Invalid TrueType font metrics.');
        }

        $cmap = $this->table('cmap');
        $records = $this->u16($cmap + 2);
        $bestPriority = -1;
        $this->cmapOffset = 0;
        $this->cmapFormat = 0;
        for ($i = 0; $i < $records; $i++) {
            $record = $cmap + 4 + 8 * $i;
            $platform = $this->u16($record);
            $encoding = $this->u16($record + 2);
            $offset = $cmap + $this->u32($record + 4);
            if ($offset + 2 > strlen($bytes)) {
                continue;
            }
            $format = $this->u16($offset);
            $priority = ($format === 12 && $platform === 3 && $encoding === 10) ? 4
                : (($format === 12 && $platform === 0) ? 3
                : (($format === 4 && $platform === 3 && $encoding === 1) ? 2
                : (($format === 4 && $platform === 0) ? 1 : -1)));
            if ($priority > $bestPriority) {
                $bestPriority = $priority;
                $this->cmapOffset = $offset;
                $this->cmapFormat = $format;
            }
        }
        if ($bestPriority < 0) {
            throw new InvalidArgumentException('TrueType font has no Unicode cmap.');
        }
    }

    public function fontData(): string
    {
        return $this->bytes;
    }

    public function glyphFor(int $codepoint): int
    {
        if ($codepoint < 0 || $codepoint > 0x10FFFF) {
            return 0;
        }
        $at = $this->cmapOffset;
        if ($this->cmapFormat === 12) {
            $count = $this->u32($at + 12);
            $lo = 0;
            $hi = $count - 1;
            while ($lo <= $hi) {
                $mid = intdiv($lo + $hi, 2);
                $group = $at + 16 + $mid * 12;
                $start = $this->u32($group);
                $end = $this->u32($group + 4);
                if ($codepoint < $start) {
                    $hi = $mid - 1;
                } elseif ($codepoint > $end) {
                    $lo = $mid + 1;
                } else {
                    return $this->u32($group + 8) + $codepoint - $start;
                }
            }
            return 0;
        }
        if ($codepoint > 0xFFFF) {
            return 0;
        }
        $segments = $this->u16($at + 6) / 2;
        $endAt = $at + 14;
        $startAt = $endAt + 2 * $segments + 2;
        $deltaAt = $startAt + 2 * $segments;
        $rangeAt = $deltaAt + 2 * $segments;
        for ($i = 0; $i < $segments; $i++) {
            if ($codepoint > $this->u16($endAt + 2 * $i)) {
                continue;
            }
            if ($codepoint < $this->u16($startAt + 2 * $i)) {
                return 0;
            }
            $delta = $this->u16($deltaAt + 2 * $i);
            $range = $this->u16($rangeAt + 2 * $i);
            if ($range === 0) {
                return ($codepoint + $delta) & 0xFFFF;
            }
            $glyphAt = $rangeAt + 2 * $i + $range + 2 * ($codepoint - $this->u16($startAt + 2 * $i));
            $glyph = $this->u16($glyphAt);
            return $glyph === 0 ? 0 : ($glyph + $delta) & 0xFFFF;
        }
        return 0;
    }

    public function widthForGlyph(int $glyph): int
    {
        $index = min($glyph, $this->hMetricsCount - 1);
        return (int) round($this->u16($this->table('hmtx') + $index * 4) * 1000 / $this->unitsPerEm);
    }

    /** @return array<string, mixed> */
    public function descriptor(string $postScriptName, string $fontFileReference): array
    {
        $scale = fn (int $v): int => (int) round($v * 1000 / $this->unitsPerEm);
        return [
            'Type' => '/FontDescriptor',
            'FontName' => '/' . $postScriptName,
            'Flags' => 4,
            'FontBBox' => [
                $scale($this->bboxLeft),
                $scale($this->bboxBottom),
                $scale($this->bboxRight),
                $scale($this->bboxTop),
            ],
            'ItalicAngle' => 0,
            'Ascent' => $scale($this->ascent),
            'Descent' => $scale($this->descent),
            'CapHeight' => $scale($this->ascent),
            'StemV' => 80,
            'FontFile2' => $fontFileReference,
        ];
    }

    /** @return list<int> */
    public static function codepoints(string $value): array
    {
        if (!preg_match('//u', $value)) {
            throw new InvalidArgumentException('Text must be valid UTF-8.');
        }
        $result = [];
        preg_match_all('/./us', $value, $matches);
        foreach ($matches[0] as $character) {
            $bytes = array_values(unpack('C*', $character));
            $n = count($bytes);
            $code = match ($n) {
                1 => $bytes[0],
                2 => (($bytes[0] & 31) << 6) | ($bytes[1] & 63),
                3 => (($bytes[0] & 15) << 12) | (($bytes[1] & 63) << 6) | ($bytes[2] & 63),
                4 => (($bytes[0] & 7) << 18) | (($bytes[1] & 63) << 12) | (($bytes[2] & 63) << 6) | ($bytes[3] & 63),
                default => throw new InvalidArgumentException('Invalid UTF-8 sequence.'),
            };
            $result[] = $code;
        }
        return $result;
    }

    public static function utf16Hex(int $codepoint): string
    {
        if ($codepoint <= 0xFFFF) {
            return sprintf('%04X', $codepoint);
        }
        $cp = $codepoint - 0x10000;
        return sprintf('%04X%04X', 0xD800 | ($cp >> 10), 0xDC00 | ($cp & 1023));
    }

    private function table(string $name): int
    {
        return $this->tables[$name]['offset'];
    }

    private function u16(int $at): int
    {
        if ($at < 0 || $at + 2 > strlen($this->bytes)) {
            throw new InvalidArgumentException('TrueType read outside font bounds.');
        }
        return unpack('n', substr($this->bytes, $at, 2))[1];
    }

    private function s16(int $at): int
    {
        $n = $this->u16($at);
        return $n > 32767 ? $n - 65536 : $n;
    }

    private function u32(int $at): int
    {
        if ($at < 0 || $at + 4 > strlen($this->bytes)) {
            throw new InvalidArgumentException('TrueType read outside font bounds.');
        }
        return unpack('N', substr($this->bytes, $at, 4))[1];
    }
}
