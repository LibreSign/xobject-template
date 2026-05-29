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

        return $lines;
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
        $currentLine = array_pop($segments) ?? '';

        foreach ($segments as $segment) {
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
        if ($hyphens !== 'manual') {
            return null;
        }

        if (!str_contains($word, "\u{00AD}")) {
            return null;
        }

        $manualBreaks = explode("\u{00AD}", $word);

        return $this->packManualSegments($manualBreaks, $maxWidth, $fontAlias, $fontSize);
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
        $remaining = $this->splitCharacters($word);
        if ($remaining === []) {
            return [$word];
        }

        $segments = [];
        $hyphenWidth = $this->fontMetrics->measureString($fontAlias, $fontSize, '-');

        while ($remaining !== []) {
            $segment = $this->resolveAutoSegment(
                $remaining,
                $maxWidth,
                $fontAlias,
                $fontSize,
                $hyphenWidth,
            );

            $consumed = count($this->splitCharacters($segment));

            $remaining = array_slice($remaining, $consumed);
            $segments[] = $remaining === [] ? $segment : ($segment . '-');
        }

        return $segments;
    }

    /**
     * @param list<string> $remaining
     * @return non-empty-string
     */
    private function resolveAutoSegment(
        array $remaining,
        float $maxWidth,
        string $fontAlias,
        float $fontSize,
        float $hyphenWidth,
    ): string {
        $segment = '';
        $remainingCount = count($remaining);

        foreach ($remaining as $index => $character) {
            $candidate = $segment . $character;
            $hasMoreCharacters = ($index + 1) < $remainingCount;
            $candidateWidth = $this->fontMetrics->measureString($fontAlias, $fontSize, $candidate)
                + ($hasMoreCharacters ? $hyphenWidth : 0.0);

            if ($candidateWidth > $maxWidth && $segment !== '') {
                break;
            }

            if ($candidateWidth > $maxWidth) {
                return $character;
            }

            $segment = $candidate;
        }

        return $segment;
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
        $hyphenWidth = $this->fontMetrics->measureString($fontAlias, $fontSize, '-');

        foreach ($segments as $index => $segment) {
            $candidate = $current . $segment;
            $candidateWidth = $this->fontMetrics->measureString($fontAlias, $fontSize, $candidate)
                + ($index === $lastIndex ? 0.0 : $hyphenWidth);
            if (
                $current !== ''
                && $candidateWidth > $maxWidth
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
