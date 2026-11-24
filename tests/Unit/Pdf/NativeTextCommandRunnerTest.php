<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf;

use InvalidArgumentException;
use LibreSign\XObjectTemplate\Pdf\NativeTextCommandRunner;
use PHPUnit\Framework\TestCase;

final class NativeTextCommandRunnerTest extends TestCase
{
    public function testNativeCommandUsesUtf8LocaleAndPassesStdinUnchanged(): void
    {
        $original = getenv('LC_ALL');
        $originalEnv = $_ENV['LC_ALL'] ?? null;
        try {
            putenv('LC_ALL=C');
            $_ENV['LC_ALL'] = 'C';
            $result = (new NativeTextCommandRunner())->run(
                [PHP_BINARY, '-r', 'echo getenv("LC_ALL"), ":", stream_get_contents(STDIN);'],
                "e\u{0301}\n",
            );
            self::assertSame("C.UTF-8:e\u{0301}\n", $result);
        } finally {
            if ($originalEnv === null) {
                unset($_ENV['LC_ALL']);
            } else {
                $_ENV['LC_ALL'] = $originalEnv;
            }
            if ($original === false) {
                putenv('LC_ALL');
            } else {
                putenv('LC_ALL=' . $original);
            }
        }
    }

    public function testNativeCommandFailsClosedWithoutShellInterpretation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('working HarfBuzz/FriBidi runtime');
        (new NativeTextCommandRunner())->run(['definitely-not-an-executable-issue33'], null);
    }

    public function testNativeCommandRejectsFailedExitStatus(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('working HarfBuzz/FriBidi runtime');
        (new NativeTextCommandRunner())->run([PHP_BINARY, '-r', 'exit(7);'], null);
    }
}
