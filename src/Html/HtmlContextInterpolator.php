<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Html;

final readonly class HtmlContextInterpolator
{
    /**
     * @param array<string, scalar> $context
     */
    public function interpolate(string $html, array $context): string
    {
        if ($context === [] || !str_contains($html, '{{')) {
            return $html;
        }

        return (string) preg_replace_callback(
            '/\{\{\s*([A-Za-z0-9_.-]+)\s*\}\}/',
            function (array $matches) use ($context): string {
                $key = $matches[1] ?? '';
                if ($key === '' || !array_key_exists($key, $context)) {
                    return $matches[0];
                }

                return htmlspecialchars(
                    $this->normalizeScalar($context[$key]),
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8',
                );
            },
            $html,
        ) ?? $html;
    }

    private function normalizeScalar(string|int|float|bool $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
