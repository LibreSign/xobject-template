<?php

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Layout;

final readonly class LayoutLine
{
    public function __construct(
        public string $text,
        public float $x,
        public float $y,
        public float $fontSize,
        public string $fontAlias,
        public string $rgbColor,
    ) {
    }
}
