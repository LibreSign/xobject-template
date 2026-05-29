<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf;

use LibreSign\XObjectTemplate\Pdf\FilesystemImageSourceReader;
use LibreSign\XObjectTemplate\Pdf\WarningToExceptionConverterInterface;
use LibreSign\XObjectTemplate\Tests\Support\UsesTemporaryFiles;
use PHPUnit\Framework\TestCase;

final class FilesystemImageSourceReaderTest extends TestCase
{
    use UsesTemporaryFiles;

    protected function tearDown(): void
    {
        $this->tearDownTemporaryFiles();
    }

    public function testReadReturnsContentsForReadableFiles(): void
    {
        $reader = new FilesystemImageSourceReader();
        $path = $this->createTemporaryFile('png', 'contents');

        self::assertSame('contents', $reader->read($path));
    }

    public function testReadUsesInjectedWarningConverter(): void
    {
        $reader = new FilesystemImageSourceReader(new class implements WarningToExceptionConverterInterface {
            public function run(callable $operation, string $message): mixed
            {
                return 'converted-contents';
            }
        });
        $path = $this->createTemporaryFile('png', 'disk-contents');

        self::assertSame('converted-contents', $reader->read($path));
    }

    public function testReadRejectsMissingSources(): void
    {
        $reader = new FilesystemImageSourceReader();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an existing file');

        $reader->read('/tmp/does-not-exist-preview.png');
    }

    public function testReadRejectsUnreadableSources(): void
    {
        $reader = new FilesystemImageSourceReader();
        $path = $this->createTemporaryFile('png', 'contents');
        chmod($path, 0);

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage(sprintf('Image source "%s" must be readable.', $path));

            $reader->read($path);
        } finally {
            chmod($path, 0644);
        }
    }
}
