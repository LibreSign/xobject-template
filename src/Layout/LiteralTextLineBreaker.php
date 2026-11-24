<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Layout;

use InvalidArgumentException;
use LibreSign\XObjectTemplate\Pdf\StandardFontMetrics;

/** @internal Prepares literal paragraphs before delegating word wrapping. */
final readonly class LiteralTextLineBreaker
{
    private TextLineBreaker $lineBreaker;

    public function __construct(private StandardFontMetrics $fontMetrics)
    {
        $this->lineBreaker = new TextLineBreaker($fontMetrics);
    }

    /** @return list<string> */
    public function wrap(string $text, float $width, string $fontAlias, float $fontSize): array
    {
        $normalized = normalizer_normalize($text);
        if (!is_string($normalized)) {
            throw new InvalidArgumentException('Literal text must be valid UTF-8.');
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $normalized) === 1) {
            throw new InvalidArgumentException('Literal text contains unsupported control characters.');
        }

        $lines = [];
        $paragraphs = explode("\n", str_replace(["\r\n", "\r"], "\n", $normalized));
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim(str_replace("\t", ' ', $paragraph));
            if ($this->fontMetrics->measureString($fontAlias, $fontSize, $paragraph) <= $width) {
                $lines[] = $paragraph;
                continue;
            }
            $words = array_filter(
                explode(' ', $paragraph),
                static fn (string $word): bool => $word !== '',
            );
            array_push(
                $lines,
                ...$this->lineBreaker->wrapWords($words, $paragraph, $width, $fontAlias, $fontSize, 'break'),
            );
        }
        return $lines;
    }
}
