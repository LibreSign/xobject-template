<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf;

use Closure;
use InvalidArgumentException;
use JsonException;

/** @internal Validate HarfBuzz glyph positions and map them to original Unicode clusters. */
final readonly class HarfBuzzRunShaper
{
    /** @param Closure(list<string>, ?string): string $run */
    public function __construct(
        private string $binary,
        private Closure $run,
        private UnicodeScalarCodec $codec,
    ) {
    }

    /**
     * @return list<array{glyph: int, glyphId: int, unicode: list<int>, advance: int, dx: int, dy: int, cluster: int}>
     */
    public function shape(
        string $fontPath,
        string $text,
        bool $rtl,
        string $before = '',
        string $after = '',
    ): array {
        return $this->mapClusters($this->shapeRun($fontPath, $text, $rtl, $before, $after), $text);
    }

    /**
     * @return list<array{g: int, cl: int, ax: int, dx: int, dy: int}>
     */
    private function shapeRun(string $fontPath, string $text, bool $rtl, string $before, string $after): array
    {
        $arguments = [
            $this->binary,
            '--no-glyph-names',
            '--output-format=json',
            '--font-size=1000',
            '--direction=' . ($rtl ? 'rtl' : 'ltr'),
            '--text=' . $text,
        ];
        if ($before !== '') {
            $arguments[] = '--text-before=' . $before;
        }
        if ($after !== '') {
            $arguments[] = '--text-after=' . $after;
        }
        $arguments[] = $fontPath;
        $output = ($this->run)($arguments, null);
        try {
            $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('HarfBuzz returned invalid JSON.', previous: $error);
        }
        if (!is_array($result) || !array_is_list($result) || $result === []) {
            throw new InvalidArgumentException('HarfBuzz returned no glyphs.');
        }
        /** @psalm-suppress MixedAssignment JSON structure is validated by assertGlyph. */
        foreach ($result as $glyph) {
            $this->assertGlyph($glyph);
        }
        /** @var list<array{g: int, cl: int, ax: int, dx: int, dy: int}> $result */
        return $result;
    }

    private function assertGlyph(mixed $glyph): void
    {
        if (!is_array($glyph) || !isset($glyph['g'], $glyph['cl'], $glyph['ax'], $glyph['dx'], $glyph['dy'])) {
            throw new InvalidArgumentException('HarfBuzz returned an invalid glyph position.');
        }
        foreach (['g', 'cl', 'ax', 'dx', 'dy'] as $field) {
            if (!isset($glyph[$field]) || !is_int($glyph[$field])) {
                throw new InvalidArgumentException('HarfBuzz returned a noninteger glyph position.');
            }
        }
        if ($glyph['g'] <= 0 || $glyph['g'] > 65535) {
            throw new InvalidArgumentException('HarfBuzz returned an invalid glyph index.');
        }
    }

    /**
     * @param list<array{g: int, cl: int, ax: int, dx: int, dy: int}> $glyphs
     * @return list<array{glyph: int, glyphId: int, unicode: list<int>, advance: int, dx: int, dy: int, cluster: int}>
     */
    private function mapClusters(array $glyphs, string $runText): array
    {
        $codepoints = $this->codec->decode($runText);
        $clusters = array_column($glyphs, 'cl');
        sort($clusters, SORT_NUMERIC);
        if (($clusters[0] ?? null) !== 0) {
            throw new InvalidArgumentException('HarfBuzz returned incomplete Unicode cluster coverage.');
        }
        $mapping = [];
        foreach ($clusters as $index => $cluster) {
            $next = $clusters[$index + 1] ?? count($codepoints);
            if ($next > count($codepoints)) {
                throw new InvalidArgumentException('HarfBuzz returned an invalid Unicode cluster boundary.');
            }
            $mapping[$cluster] = array_slice($codepoints, $cluster, $next - $cluster);
        }
        $used = [];
        $result = [];
        foreach ($glyphs as $glyph) {
            $cluster = $glyph['cl'];
            $unicode = array_key_exists($cluster, $used) ? [0x200B] : $mapping[$cluster];
            $used[$cluster] = $unicode;
            $result[] = [
                'glyph' => $mapping[$cluster][0],
                'cluster' => $cluster,
                'glyphId' => $glyph['g'],
                'unicode' => $unicode,
                'advance' => $glyph['ax'],
                'dx' => $glyph['dx'],
                'dy' => $glyph['dy'],
            ];
        }
        return $result;
    }
}
