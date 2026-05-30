<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf;

use InvalidArgumentException;
use LibreSign\XObjectTemplate\Dto\CompileResult;

/** @internal */
class CompileResultResourceExtractor
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function extract(CompileResult $result, string $resourceType, string $itemMessageTemplate): array
    {
        if (!array_key_exists($resourceType, $result->resources)) {
            return [];
        }

        /** @var mixed $rawResources */
        $rawResources = $result->resources[$resourceType];
        $resources = $this->requireStringKeyedArray(
            $rawResources,
            sprintf('%s resources must be an array.', $resourceType),
        );

        $normalizedResources = [];
        foreach (array_keys($resources) as $alias) {
            $normalizedResources[$alias] = $this->requireStringKeyedArray(
                $resources[$alias],
                sprintf($itemMessageTemplate, $alias),
            );
        }

        return $normalizedResources;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireStringKeyedArray(mixed $value, string $message): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException($message);
        }

        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                throw new InvalidArgumentException($message);
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
