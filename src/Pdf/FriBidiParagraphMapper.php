<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf;

use Closure;
use InvalidArgumentException;

/** @internal Convert logical Unicode characters into visual, directional shaping runs. */
final readonly class FriBidiParagraphMapper
{
    /** @param Closure(list<string>, ?string): string $run */
    public function __construct(
        private string $binary,
        private Closure $run,
    ) {
    }

    /**
     * @param list<string> $characters
     * @return list<array{text: string, rtl: bool, visual: int, start: int}>
     */
    public function runs(string $text, array $characters): array
    {
        [$visual, $levels] = $this->bidiMaps($text, count($characters));
        return $this->visualRuns($characters, $visual, $levels);
    }

    /**
     * @return array{list<int>, list<int>}
     */
    private function bidiMaps(string $text, int $count): array
    {
        $output = ($this->run)([
            $this->binary,
            '--novisual',
            '--ltov',
            '--vtol',
            '--levels',
            '--basedir',
            '--charset=UTF-8',
            '--nopad',
        ], $text . "\n");
        $lines = explode("\n", trim($output));
        if (count($lines) !== 4 || !str_starts_with($lines[0], 'Base direction: ')) {
            throw new InvalidArgumentException('FriBidi returned an invalid paragraph map.');
        }
        $visual = $this->parseMap($lines[1], $count);
        $inverse = $this->parseMap($lines[2], $count);
        $levels = $this->parseMap($lines[3], $count);
        foreach ($visual as $logicalIndex => $visualIndex) {
            if ($visualIndex < 0 || $visualIndex >= $count || $inverse[$visualIndex] !== $logicalIndex) {
                throw new InvalidArgumentException('FriBidi returned inconsistent visual positions.');
            }
        }
        return [$visual, $levels];
    }

    /** @return list<int> */
    private function parseMap(string $line, int $count): array
    {
        $tokens = preg_split('/\s+/', trim($line));
        if (count($tokens) !== $count) {
            throw new InvalidArgumentException('FriBidi returned an inconsistent character map.');
        }
        $values = [];
        foreach ($tokens as $token) {
            if (!ctype_digit($token)) {
                throw new InvalidArgumentException('FriBidi returned a nonnumeric character map.');
            }
            $values[] = intval($token);
        }
        return $values;
    }

    /**
     * @param list<string> $characters
     * @param list<int> $visualPositions
     * @param list<int> $levels
     * @return list<array{text: string, rtl: bool, visual: int, start: int}>
     */
    private function visualRuns(array $characters, array $visualPositions, array $levels): array
    {
        $runs = [];
        $start = 0;
        $script = $this->scriptOf($characters[0]);
        for ($i = 1, $length = count($characters); $i <= $length; $i++) {
            $nextScript = $i < $length ? $this->scriptOf($characters[$i]) : '';
            if ($this->shouldContinueRun($i, $length, $levels, $start, $nextScript, $script)) {
                $script = $nextScript !== '' ? $nextScript : $script;
                continue;
            }
            /** @var non-empty-list<int> $positions Run boundaries always contain at least one character. */
            $positions = array_slice($visualPositions, $start, $i - $start);
            $runs[] = [
                'text' => implode('', array_slice($characters, $start, $i - $start)),
                'rtl' => ($levels[$start] % 2) === 1,
                'visual' => min($positions),
                'start' => $start,
            ];
            $start = $i;
            $script = $nextScript;
        }
        usort($runs, static fn (array $first, array $second): int => $first['visual'] <=> $second['visual']);
        return $runs;
    }

    /** @param list<int> $levels */
    private function shouldContinueRun(
        int $index,
        int $count,
        array $levels,
        int $start,
        string $nextScript,
        string $script,
    ): bool {
        return $index < $count
            && $levels[$index] === $levels[$start]
            && ($nextScript === $script || $nextScript === '' || $script === '');
    }

    private function scriptOf(string $character): string
    {
        $patterns = [
            '/\p{Arabic}/u' => 'Arab',
            '/\p{Hebrew}/u' => 'Hebr',
            '/\p{Latin}/u' => 'Latn',
            '/\p{Cyrillic}/u' => 'Cyrl',
        ];
        foreach ($patterns as $pattern => $script) {
            if (preg_match($pattern, $character) === 1) {
                return $script;
            }
        }
        return '';
    }
}
