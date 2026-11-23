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
    private readonly string $bytes;
    /** @var array<string, array{offset: int, length: int}> */
    private array $tables = [];
    private readonly TrueTypeCmap $cmap;
    private readonly SfntTableReader $reader;
    private readonly int $unitsPerEm;
    private readonly int $hMetricsCount;
    private readonly int $glyphCount;
    private readonly int $ascent;
    private readonly int $descent;
    private readonly int $bboxLeft;
    private readonly int $bboxBottom;
    private readonly int $bboxRight;
    private readonly int $bboxTop;

    public function __construct(string $path)
    {
        $this->bytes = $this->readFontData($path);
        $this->reader = new SfntTableReader($this->bytes);
        $this->readTableDirectory();
        $metrics = $this->readFontMetrics();
        $this->unitsPerEm = $metrics['unitsPerEm'];
        $this->bboxLeft = $metrics['bboxLeft'];
        $this->bboxBottom = $metrics['bboxBottom'];
        $this->bboxRight = $metrics['bboxRight'];
        $this->bboxTop = $metrics['bboxTop'];
        $this->ascent = $metrics['ascent'];
        $this->descent = $metrics['descent'];
        $this->hMetricsCount = $metrics['hMetricsCount'];
        $this->glyphCount = $metrics['glyphCount'];
        $this->cmap = new TrueTypeCmap($this->reader, $this->table('cmap'), $this->tables['cmap']['length']);
    }

    private function readFontData(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException('TrueType font file cannot be read.');
        }
        $size = filesize($path);
        if ($size === false || $size < 64 || $size > 32 * 1024 * 1024) {
            throw new InvalidArgumentException('TrueType font file must be between 64 bytes and 32 MiB.');
        }
        $bytes = file_get_contents($path);
        if ($bytes === false || strlen($bytes) !== $size) {
            throw new InvalidArgumentException('TrueType font file cannot be read.');
        }
        return $bytes;
    }

    private function readTableDirectory(): void
    {
        if ($this->u32(0) !== 0x00010000) {
            throw new InvalidArgumentException('Only sfnt TrueType outlines are supported (not OTF/CFF or TTC).');
        }
        $count = $this->u16(4);
        if ($count > 128 || 12 + $count * 16 > strlen($this->bytes)) {
            throw new InvalidArgumentException('Invalid TrueType table directory.');
        }
        for ($index = 0; $index < $count; $index++) {
            $entry = 12 + $index * 16;
            $name = substr($this->bytes, $entry, 4);
            $offset = $this->u32($entry + 8);
            $length = $this->u32($entry + 12);
            if ($offset > strlen($this->bytes) || $length > strlen($this->bytes) - $offset) {
                throw new InvalidArgumentException('TrueType table exceeds font bounds.');
            }
            $this->tables[$name] = ['offset' => $offset, 'length' => $length];
        }
        foreach (['head', 'hhea', 'hmtx', 'maxp', 'cmap'] as $required) {
            if (!isset($this->tables[$required])) {
                throw new InvalidArgumentException('Missing TrueType table: ' . $required);
            }
        }
    }

    /**
     * @return array{
     *     unitsPerEm: int, bboxLeft: int, bboxBottom: int, bboxRight: int, bboxTop: int,
     *     ascent: int, descent: int, hMetricsCount: int, glyphCount: int
     * }
     */
    private function readFontMetrics(): array
    {
        $unitsPerEm = $this->u16($this->table('head') + 18);
        $bboxLeft = $this->s16($this->table('head') + 36);
        $bboxBottom = $this->s16($this->table('head') + 38);
        $bboxRight = $this->s16($this->table('head') + 40);
        $bboxTop = $this->s16($this->table('head') + 42);
        $ascent = $this->s16($this->table('hhea') + 4);
        $descent = $this->s16($this->table('hhea') + 6);
        $hMetricsCount = $this->u16($this->table('hhea') + 34);
        $glyphCount = $this->u16($this->table('maxp') + 4);
        if ($unitsPerEm === 0 || $glyphCount === 0 || $hMetricsCount === 0) {
            throw new InvalidArgumentException('Invalid TrueType font metrics.');
        }
        if ($hMetricsCount > $glyphCount) {
            throw new InvalidArgumentException('Invalid TrueType horizontal metrics count.');
        }
        if ($this->table('hmtx') + $hMetricsCount * 4 > strlen($this->bytes)) {
            throw new InvalidArgumentException('Truncated TrueType horizontal metrics table.');
        }
        $this->checkEmbeddingRights();
        return [
            'unitsPerEm' => $unitsPerEm,
            'bboxLeft' => $bboxLeft,
            'bboxBottom' => $bboxBottom,
            'bboxRight' => $bboxRight,
            'bboxTop' => $bboxTop,
            'ascent' => $ascent,
            'descent' => $descent,
            'hMetricsCount' => $hMetricsCount,
            'glyphCount' => $glyphCount,
        ];
    }

    private function checkEmbeddingRights(): void
    {
        if (!isset($this->tables['OS/2'])) {
            return;
        }
        $embeddingFlags = $this->u16($this->table('OS/2') + 8);
        if (($embeddingFlags & 0x0002) !== 0 || ($embeddingFlags & 0x0200) !== 0) {
            throw new InvalidArgumentException('TrueType font embedding is restricted by OS/2 fsType.');
        }
    }

    public function fontData(): string
    {
        return $this->bytes;
    }

    public function glyphFor(int $codepoint): int
    {
        $glyph = $this->cmap->glyphFor($codepoint);
        return $glyph >= $this->glyphCount ? 0 : $glyph;
    }

    public function widthForGlyph(int $glyph): int
    {
        $index = min($glyph, $this->hMetricsCount - 1);
        return (int) round($this->u16($this->table('hmtx') + $index * 4) * 1000 / $this->unitsPerEm);
    }

    /** @return array<string, mixed> */
    public function descriptor(string $postScriptName, string $fontFileReference): array
    {
        $scale = fn (int $value): int => (int) round($value * 1000 / $this->unitsPerEm);
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
        return (new UnicodeScalarCodec())->decode($value);
    }

    public static function utf16Hex(int $codepoint): string
    {
        return (new UnicodeScalarCodec())->encodeUtf16Hex($codepoint);
    }

    private function table(string $name): int
    {
        return $this->tables[$name]['offset'];
    }

    private function u16(int $offset): int
    {
        return $this->reader->u16($offset);
    }

    private function s16(int $offset): int
    {
        return $this->reader->s16($offset);
    }

    private function u32(int $offset): int
    {
        return $this->reader->u32($offset);
    }
}
