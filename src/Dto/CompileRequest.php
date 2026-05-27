<?php

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Dto;

final readonly class CompileRequest
{
    public function __construct(
        public string $html,
        public float $width = 240.0,
        public float $height = 84.0,
        /** @var array<string, scalar> */
        public array $context = [],
    ) {
    }
}
