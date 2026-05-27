<?php

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Layout;

final readonly class LayoutResult
{
    /**
     * @param list<LayoutLine> $lines
     * @param list<LayoutImage> $images
     */
    public function __construct(
        public array $lines,
        public array $images,
    ) {
    }
}
