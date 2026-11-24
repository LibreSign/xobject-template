<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf;

use Closure;
use InvalidArgumentException;

/**
 * Native shaping via HarfBuzz and Unicode bidirectional runs via FriBidi.
 *
 * The native executables are invoked with argument arrays, never a shell.
 * Text is limited and temporary font data is removed after each invocation.
 */
final readonly class HarfBuzzComplexTextShaper implements ComplexTextShaperInterface
{
    /** @var Closure(list<string>, ?string): string */
    private Closure $run;

    /**
     * @param ?Closure(list<string>, ?string): string $commandRunner
     */
    public function __construct(
        private string $harfbuzzBinary = 'hb-shape',
        private string $fribidiBinary = 'fribidi',
        ?Closure $commandRunner = null,
        private UnicodeScalarCodec $codec = new UnicodeScalarCodec(),
        private TemporaryFontFile $temporaryFontFile = new TemporaryFontFile(),
    ) {
        $this->run = $commandRunner ?? (new NativeTextCommandRunner())->run(...);
    }

    /**
     * @return list<array{
     *   glyph: int, glyphId: int, unicode: list<int>, advance: int,
     *   dx: int, dy: int, cluster: int, logical: int
     * }>
     */
    public function shape(string $text, TrueTypeFont $font): array
    {
        $runs = $this->paragraphRuns($text);
        if ($runs === []) {
            return [];
        }
        $runShaper = new HarfBuzzRunShaper($this->harfbuzzBinary, $this->run, $this->codec);
        return $this->temporaryFontFile->withFont(
            $font->fontData(),
            static function (string $path) use ($runs, $runShaper): array {
                $result = [];
                foreach ($runs as $run) {
                    foreach ($runShaper->shape($path, $run['text'], $run['rtl']) as $glyph) {
                        $glyph['logical'] = $run['start'] + $glyph['cluster'];
                        $result[] = $glyph;
                    }
                }
                return $result;
            },
        );
    }

    /**
     * @param list<TrueTypeFont> $fonts
     * @return list<array{
     *   fontIndex: int, glyph: int, glyphId: int, unicode: list<int>,
     *   advance: int, dx: int, dy: int, cluster: int, logical: int
     * }>
     */
    public function shapeWithFonts(string $text, array $fonts): array
    {
        $runs = $this->fontRuns($this->paragraphRuns($text), $fonts);
        $runShaper = new HarfBuzzRunShaper($this->harfbuzzBinary, $this->run, $this->codec);
        $result = [];
        foreach ($runs as $run) {
            $glyphs = $this->temporaryFontFile->withFont(
                $fonts[$run['fontIndex']]->fontData(),
                static fn (string $path): array => $runShaper->shape(
                    $path,
                    $run['text'],
                    $run['rtl'],
                    $run['before'],
                    $run['after'],
                ),
            );
            foreach ($glyphs as $glyph) {
                $result[] = $glyph + [
                    'logical' => $run['start'] + $glyph['cluster'],
                    'fontIndex' => $run['fontIndex'],
                ];
            }
        }
        return $result;
    }

    /** @return list<array{text: string, rtl: bool, visual: int, start: int}> */
    private function paragraphRuns(string $text): array
    {
        $codepoints = $this->codec->decode($text);
        if ($codepoints === []) {
            return [];
        }
        if (count($codepoints) > 4096 || preg_match('/[\r\n\x00]/', $text) === 1) {
            throw new InvalidArgumentException('Complex text must be a single line with at most 4096 Unicode scalars.');
        }

        /** @var list<string> $characters */
        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $runs = (new FriBidiParagraphMapper($this->fribidiBinary, $this->run))->runs($text, $characters);
        if (count($runs) > 32) {
            throw new InvalidArgumentException('Complex text exceeds the safe number of shaping runs.');
        }
        return $runs;
    }

    /**
     * @param list<array{text: string, rtl: bool, visual: int, start: int}> $bidiRuns
     * @param list<TrueTypeFont> $fonts
     * @return list<array{fontIndex: int, text: string, start: int, rtl: bool, before: string, after: string}>
     */
    private function fontRuns(array $bidiRuns, array $fonts): array
    {
        $result = [];
        $selector = new UnicodeFontRunSelector();
        foreach ($bidiRuns as $bidiRun) {
            $segments = $selector->runs($bidiRun['text'], $fonts);
            if ($segments === null) {
                throw new InvalidArgumentException(
                    'Unicode text requires an embedded TrueType font covering every shaped glyph.',
                );
            }
            $segments = $this->withRunContext($segments, $bidiRun['start'], $bidiRun['rtl']);
            array_push($result, ...$segments);
        }
        if (count($result) > 32) {
            throw new InvalidArgumentException('Complex text exceeds the safe number of shaping runs.');
        }
        return $result;
    }

    /**
     * @param list<array{fontIndex: int, text: string, start: int}> $segments
     * @return list<array{fontIndex: int, text: string, start: int, rtl: bool, before: string, after: string}>
     */
    private function withRunContext(array $segments, int $start, bool $rtl): array
    {
        $before = '';
        $after = implode('', array_column($segments, 'text'));
        $result = [];
        foreach ($segments as $segment) {
            $after = substr($after, strlen($segment['text']));
            $segment['start'] += $start;
            $result[] = $segment + ['rtl' => $rtl, 'before' => $before, 'after' => $after];
            $before .= $segment['text'];
        }
        return $rtl ? array_reverse($result) : $result;
    }
}
