<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf;

use ErrorException;
use InvalidArgumentException;

/** @internal */
final readonly class PhpWarningToExceptionConverter implements WarningToExceptionConverterInterface
{
    public function run(callable $operation, string $message): mixed
    {
        set_error_handler(
            static function (int $severity, string $warning) use ($message): never {
                throw new InvalidArgumentException(
                    $message,
                    0,
                    new ErrorException($warning, 0, $severity),
                );
            },
        );

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }
}
