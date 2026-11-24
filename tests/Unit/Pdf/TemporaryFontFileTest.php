<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf;

use InvalidArgumentException;
use LibreSign\XObjectTemplate\Pdf\TemporaryFontFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TemporaryFontFileTest extends TestCase
{
    public function testCreatesPrivateFontFileAndRemovesItAfterSuccessfulCallback(): void
    {
        $path = null;
        $file = new TemporaryFontFile();
        $result = $file->withFont("FONT\x00DATA", static function (string $temporary) use (&$path): string {
            $path = $temporary;
            self::assertFileExists($temporary);
            self::assertSame("FONT\x00DATA", file_get_contents($temporary));
            self::assertSame(0600, fileperms($temporary) & 0777);
            return hash_file('sha256', $temporary);
        });
        self::assertSame(hash('sha256', "FONT\x00DATA"), $result);
        self::assertNotNull($path);
        self::assertFileDoesNotExist($path);
    }

    public function testRemovesTemporaryFileWhenCallbackThrows(): void
    {
        $path = null;
        try {
            (new TemporaryFontFile())->withFont('FONT', static function (string $temporary) use (&$path): never {
                $path = $temporary;
                self::assertFileExists($temporary);
                throw new RuntimeException('Worker failed.');
            });
            self::fail('Exception must propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('Worker failed.', $exception->getMessage());
        }
        self::assertNotNull($path);
        self::assertFileDoesNotExist($path);
    }

    public function testRejectsFailedTemporaryFileCreationWithoutInvokingWorker(): void
    {
        $file = new TemporaryFontFile(static fn (): false => false);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to prepare temporary font data');
        $file->withFont('FONT', static function (): never {
            throw new RuntimeException('Must not execute callback.');
        });
    }

    #[DataProvider('badWritesProvider')]
    public function testRejectsIncompleteTemporaryFileWrite(int|false $writeResult): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tempfont-fail-');
        self::assertNotFalse($path);
        try {
            $file = new TemporaryFontFile(
                static fn (): string => $path,
                static fn (string $name, string $bytes): int|false => $writeResult,
            );
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Unable to write temporary font data');
            $file->withFont('FONT', static function (): never {
                throw new RuntimeException('Must not execute callback.');
            });
        } finally {
            self::assertFileDoesNotExist($path);
        }
    }

    /** @return iterable<string, array{writeResult: int|false}> */
    public static function badWritesProvider(): iterable
    {
        yield 'failed write' => [false];
        yield 'zero bytes' => [0];
        yield 'partial write' => [3];
        yield 'more than written' => [5];
    }

    public function testCustomWriteCallbackReceivesExactData(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tempfont-success-');
        self::assertNotFalse($path);
        $received = null;
        $file = new TemporaryFontFile(
            static fn (): string => $path,
            static function (string $name, string $data) use (&$received): int {
                $received = [$name, $data];
                return file_put_contents($name, $data);
            },
        );
        self::assertSame(4, $file->withFont('DATA', static fn (string $name): int => filesize($name)));
        self::assertSame([$path, 'DATA'], $received);
        self::assertFileDoesNotExist($path);
    }
}
