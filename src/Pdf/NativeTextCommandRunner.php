<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf;

use InvalidArgumentException;
use Symfony\Component\Process\Process;
use Throwable;

/** @internal Execute only argument-vector native shaping commands with bounded runtime. */
final readonly class NativeTextCommandRunner
{
    /**
     * @param list<string> $command
     */
    public function run(array $command, ?string $stdin): string
    {
        try {
            $process = new Process($command, null, ['LC_ALL' => 'C.UTF-8'], $stdin, 8.0);
            $process->mustRun();
            return $process->getOutput();
        } catch (Throwable $error) {
            throw new InvalidArgumentException('A working HarfBuzz/FriBidi runtime is required.', previous: $error);
        }
    }
}
