<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Css;

final readonly class StyleMap
{
    /**
     * @param array<string, string> $properties
     */
    public function __construct(public array $properties)
    {
    }

    public function get(string $property, ?string $default = null): ?string
    {
        return $this->properties[$property] ?? $default;
    }
}
