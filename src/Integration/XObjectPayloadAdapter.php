<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Integration;

use LibreSign\XObjectTemplate\Dto\CompileResult;

final class XObjectPayloadAdapter
{
    /**
     * Output compatible with consumers expecting a generic Form XObject payload.
     *
     * @return array{
     *     stream: string,
     *     resources: array<string, mixed>,
     *     bbox: array{0: float, 1: float, 2: float, 3: float}
     * }
     */
    public function toXObjectPayload(CompileResult $result): array
    {
        return [
            'stream' => $result->contentStream,
            'resources' => $result->resources,
            'bbox' => $result->bbox,
        ];
    }
}
