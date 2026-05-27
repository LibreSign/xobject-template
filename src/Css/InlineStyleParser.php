<?php

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Css;

final class InlineStyleParser
{
    public function parse(string $style): StyleMap
    {
        $result = [];

        foreach (explode(';', $style) as $chunk) {
            $parts = explode(':', $chunk, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $name = strtolower(trim($parts[0]));
            $value = trim($parts[1]);

            if ($name === '' || $value === '') {
                continue;
            }

            $result[$name] = $value;
        }

        return new StyleMap($result);
    }
}
