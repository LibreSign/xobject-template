<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Layout;

use LibreSign\XObjectTemplate\Pdf\StandardFontMetrics;

/** @internal */
final readonly class TextLineBreaker
{
    public function __construct(private StandardFontMetrics $fontMetrics)
    {
    }

    /**
     * @return list<string>
     */
    public function wrap(
        string $text,
        float $maxWidth,
        string $fontAlias,
        float $fontSize,
        string $hyphens,
        string $whiteSpace,
    ): array {
        if ($whiteSpace === 'nowrap' || $maxWidth <= 0.0) {
            return [$text];
        }

        $words = $this->splitWords($text);
        if ($words === []) {
            return [$text];
        }

        $lines = [];
        $currentLine = '';

        foreach ($words as $word) {
            if ($this->fitsOnCurrentLine($currentLine, $word, $maxWidth, $fontAlias, $fontSize)) {
                $currentLine = $this->appendWord($currentLine, $word);
                continue;
            }

            if ($currentLine !== '') {
                $lines[] = $currentLine;
                $currentLine = '';
            }

            $this->appendBrokenWord(
                $word,
                $maxWidth,
                $fontAlias,
                $fontSize,
                $hyphens,
                $lines,
                $currentLine,
            );
        }

        if ($currentLine !== '') {
            $lines[] = $currentLine;
        }

        return $lines === [] ? [$text] : $lines;
    }

    private function fitsOnCurrentLine(
        string $currentLine,
        string $word,
        float $maxWidth,
        string $fontAlias,
        float $fontSize,
    ): bool {
        $candidate = $this->appendWord($currentLine, $word);

        return $this->fontMetrics->measureString($fontAlias, $fontSize, $candidate) <= $maxWidth;
    }

    private function appendWord(string $currentLine, string $word): string
    {
        return $currentLine === '' ? $word : $currentLine . ' ' . $word;
    }

    /**
     * @param list<string> $lines
     */
    private function appendBrokenWord(
        string $word,
        float $maxWidth,
        string $fontAlias,
        float $fontSize,
        string $hyphens,
        array &$lines,
        string &$currentLine,
    ): void {
        $segments = $this->breakWord($word, $maxWidth, $fontAlias, $fontSize, $hyphens);
        $lastIndex = count($segments) - 1;

        foreach ($segments as $index => $segment) {
            if ($index === $lastIndex) {
                $currentLine = $segment;
                continue;
            }

            $lines[] = $segment;
        }
    }

    /**
     * @return list<string>
     */
    private function breakWord(
        string $word,
        float $maxWidth,
        string $fontAlias,
        float $fontSize,
        string $hyphens,
    ): array {
        if ($hyphens === 'none') {
            return [$word];
        }

        $manualSegments = $this->resolveManualBreaks($word, $maxWidth, $fontAlias, $fontSize, $hyphens);
        if ($manualSegments !== null) {
            return $manualSegments;
        }

        if ($hyphens !== 'auto') {
            return [$word];
        }

        return $this->breakWordAutomatically($word, $maxWidth, $fontAlias, $fontSize);
    }

    /**
     * @return list<string>|null
     */
    private function resolveManualBreaks(
        string $word,
        float $maxWidth,
        string $fontAlias,
        float $fontSize,
        string $hyphens,
    ): ?array {
        if ($hyphens !== 'manual' || !str_contains($word, "\u{00AD}")) {
            return null;
        }

        $manualBreaks = explode("\u{00AD}", $word);

        return count($manualBreaks) > 1
            ? $this->packManualSegments($manualBreaks, $maxWidth, $fontAlias, $fontSize)
            : null;
    }

    /**
     * @return list<string>
     */
    private function breakWordAutomatically(
        string $word,
        float $maxWidth,
        string $fontAlias,
        float $fontSize,
    ): array {
        $segments = [];
        $remaining = $this->splitCharacters($word);

        while ($remaining !== []) {
            ['segment' => $segment, 'consumed' => $consumed] = $this->resolveAutoSegment(
                $remaining,
                $maxWidth,
                $fontAlias,
                $fontSize,
            );

            if ($consumed <= 0) {
                break;
            }

            $remaining = array_slice($remaining, $consumed);
            $segments[] = $remaining === [] ? $segment : ($segment . '-');
        }

        return $segments === [] ? [$word] : $segments;
    }

    /**
     * @param list<string> $remaining
     * @return array{segment: string, consumed: int}
     */
    private function resolveAutoSegment(
        array $remaining,
        float $maxWidth,
        string $fontAlias,
        float $fontSize,
    ): array {
        $segment = '';
        $consumed = 0;
        $remainingCount = count($remaining);

        foreach ($remaining as $index => $character) {
            $candidate = $segment . $character;
            $isLastCharacter = $index === ($remainingCount - 1);
            $candidateWidth = $this->fontMetrics->measureString(
                $fontAlias,
                $fontSize,
                $candidate . ($isLastCharacter ? '' : '-'),
            );

            if ($candidateWidth > $maxWidth && $segment !== '') {
                break;
            }

            if ($candidateWidth > $maxWidth) {
                return ['segment' => $character, 'consumed' => 1];
            }

            $segment = $candidate;
            $consumed = $index + 1;
        }

        return ['segment' => $segment, 'consumed' => $consumed];
    }

    /**
     * @param list<string> $segments
     * @return list<string>
     */
    private function packManualSegments(
        array $segments,
        float $maxWidth,
        string $fontAlias,
        float $fontSize,
    ): array {
        $packed = [];
        $current = '';
        $lastIndex = count($segments) - 1;

        foreach ($segments as $index => $segment) {
            $candidate = $current . $segment;
            $candidateWithHyphen = $candidate . ($index === $lastIndex ? '' : '-');
            if (
                $current !== ''
                && $this->fontMetrics->measureString($fontAlias, $fontSize, $candidateWithHyphen) > $maxWidth
            ) {
                $packed[] = $current . '-';
                $current = $segment;
                continue;
            }

            $current = $candidate;
        }

        if ($current !== '') {
            $packed[] = $current;
        }

        return $packed === [] ? [implode('', $segments)] : $packed;
    }

    /**
     * @return list<string>
     */
    private function splitWords(string $text): array
    {
        $words = preg_split('/\s+/u', $text) ?: [];

        return array_values(
            array_filter($words, static fn (string $word): bool => $word !== ''),
        );
    }

    /**
     * @return list<string>
     */
    private function splitCharacters(string $text): array
    {
        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return $characters === false ? [] : $characters;
    }
}
