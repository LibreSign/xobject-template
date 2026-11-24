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
}
