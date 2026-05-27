<?php

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Dto;

final readonly class CompileResult
{
    /**
     * @param array<string, mixed> $resources
     * @param array{0: float, 1: float, 2: float, 3: float} $bbox
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $contentStream,
        public array $resources,
        public array $bbox,
        public array $metadata = [],
    ) {
    }
}
