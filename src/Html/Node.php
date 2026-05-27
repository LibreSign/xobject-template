<?php

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Html;

final readonly class Node
{
    /**
     * @param array<string, string> $attributes
     * @param list<self> $children
     */
    public function __construct(
        public string $tag,
        public string $text,
        public array $attributes = [],
        public array $children = [],
    ) {
    }
}
