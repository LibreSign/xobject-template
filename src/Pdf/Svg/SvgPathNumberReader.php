<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace LibreSign\XObjectTemplate\Pdf\Svg;

use InvalidArgumentException;

final readonly class SvgPathNumberReader
{
    /**
     * @param list<string> $tokens
     * @return list<float>
     */
    public function readPathNumbers(array $tokens, int &$index, int $count, string $source): array
    {
        $values = [];

        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$index] ?? null;

            if ($token === null || strlen($token) === 1 && ctype_alpha($token)) {
                throw new InvalidArgumentException(sprintf(
                    'Malformed SVG path data in "%s".',
                    $source,
                ));
            }

            $values[] = (float) $token;
            ++$index;
        }

        return $values;
    }
}
