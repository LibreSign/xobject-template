<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf;

use ErrorException;
use LibreSign\XObjectTemplate\Pdf\PhpWarningToExceptionConverter;
use PHPUnit\Framework\TestCase;

final class PhpWarningToExceptionConverterTest extends TestCase
{
    public function testRunReturnsOperationResult(): void
    {
        $converter = new PhpWarningToExceptionConverter();

        self::assertSame('ok', $converter->run(static fn (): string => 'ok', 'unused'));
    }

    public function testRunConvertsWarningsToInvalidArgumentExceptions(): void
    {
        $converter = new PhpWarningToExceptionConverter();

        try {
            $converter->run(
                static function (): void {
                    trigger_error('warning-from-test', E_USER_WARNING);
                },
                'converted message',
            );
            self::fail('Expected the warning to be converted into an exception.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('converted message', $exception->getMessage());
            self::assertSame(0, $exception->getCode());
            self::assertInstanceOf(ErrorException::class, $exception->getPrevious());
            self::assertSame(0, $exception->getPrevious()?->getCode());
        }
    }
}
