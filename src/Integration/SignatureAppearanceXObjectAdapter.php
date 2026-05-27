<?php

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Integration;

use LibreSign\XObjectTemplate\Dto\CompileResult;

final class SignatureAppearanceXObjectAdapter
{
    /**
     * Output compatible with consumers expecting stream/resources pair.
     *
        * @return array{
        *     stream: string,
        *     resources: array<string, mixed>,
        *     bbox: array{0: float, 1: float, 2: float, 3: float}
        * }
     */
    public function toPdfSignerPayload(CompileResult $result): array
    {
        return [
            'stream' => $result->contentStream,
            'resources' => $result->resources,
            'bbox' => $result->bbox,
        ];
    }
}
