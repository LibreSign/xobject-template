<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit;

use InvalidArgumentException;
use LibreSign\XObjectTemplate\Dto\PlainTextBlock;
use LibreSign\XObjectTemplate\PlainTextCompiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlainTextCompilerTest extends TestCase
{
    public function testPositionsAndClipsTextBoxes(): void
    {
        $result = (new PlainTextCompiler())->compile([
            new PlainTextBlock("A\nB\nC", 10, 5, 60, 20),
            new PlainTextBlock('Al', 80, 0, 100, 80, 20, 'center', true),
        ], 180, 80);

        self::assertSame([0.0, 0.0, 180.0, 80.0], $result->bbox);
        self::assertSame(3, $result->metadata['line_count']);
        self::assertStringContainsString('1 0 0 1 10.000000 65.000000 Tm', $result->contentStream);
        self::assertStringContainsString('1 0 0 1 121.110000 30.000000 Tm', $result->contentStream);
        self::assertStringNotContainsString('(C) Tj', $result->contentStream);
    }

    #[DataProvider('invalidGeometry')]
    public function testRejectsInvalidGeometry(float $width, float $height, float $size): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PlainTextBlock('Text', 0, 0, $width, $height, $size);
    }

    public static function invalidGeometry(): iterable
    {
        yield 'zero width' => [0.0, 20.0, 10.0];
        yield 'negative height' => [20.0, -1.0, 10.0];
        yield 'zero font' => [20.0, 20.0, 0.0];
        yield 'nonfinite' => [INF, 20.0, 10.0];
    }

    public function testRejectsInvalidCanvas(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PlainTextCompiler())->compile([], NAN, 40);
    }

    public function testRejectsMalformedUtf8(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PlainTextCompiler())->compile([new PlainTextBlock("\xc3\x28", 0, 0, 80, 40)], 80, 40);
    }
}
