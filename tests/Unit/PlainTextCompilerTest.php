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
            new PlainTextBlock("A\nB\nC", 10, 5, 60, 26),
            new PlainTextBlock('Al', 80, 5, 100, 70, 20, 'center', 'center'),
        ], 180, 80);

        self::assertSame([0.0, 0.0, 180.0, 80.0], $result->bbox);
        self::assertSame(0, $result->metadata['node_count']);
        self::assertSame(3, $result->metadata['line_count']);
        self::assertStringContainsString('1 0 0 1 10.000000 65.000000 Tm', $result->contentStream);
        self::assertStringContainsString('1 0 0 1 121.110000 30.000000 Tm', $result->contentStream);
        self::assertStringNotContainsString('(C) Tj', $result->contentStream);
    }

    #[DataProvider('invalidGeometry')]
    public function testRejectsInvalidGeometry(float $x, float $y, float $width, float $height, float $size): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PlainTextBlock('Text', $x, $y, $width, $height, $size);
    }

    public static function invalidGeometry(): iterable
    {
        yield 'zero width' => [0.0, 0.0, 0.0, 20.0, 10.0];
        yield 'negative height' => [0.0, 0.0, 20.0, -1.0, 10.0];
        yield 'zero height' => [0.0, 0.0, 20.0, 0.0, 10.0];
        yield 'zero font' => [0.0, 0.0, 20.0, 20.0, 0.0];
        yield 'infinite width' => [0.0, 0.0, INF, 20.0, 10.0];
        yield 'nonfinite x' => [NAN, 0.0, 20.0, 20.0, 10.0];
        yield 'nonfinite y' => [0.0, NAN, 20.0, 20.0, 10.0];
        yield 'nonfinite height' => [0.0, 0.0, 20.0, INF, 10.0];
        yield 'nonfinite font' => [0.0, 0.0, 20.0, 20.0, INF];
    }

    #[DataProvider('invalidCanvasProvider')]
    public function testRejectsInvalidCanvas(float $width, float $height): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PlainTextCompiler())->compile([], $width, $height);
    }

    public static function invalidCanvasProvider(): iterable
    {
        yield 'nonfinite width' => [NAN, 40.0];
        yield 'nonfinite height' => [40.0, INF];
        yield 'zero width' => [0.0, 40.0];
        yield 'zero height' => [40.0, 0.0];
    }

    public function testDefaultTopAlignmentAndExplicitCenterWithNoSpareHeight(): void
    {
        $result = (new PlainTextCompiler())->compile([
            new PlainTextBlock('Top', 10, 5, 70, 50),
            new PlainTextBlock('Centered', 80, 5, 100, 10, verticalAlign: 'center'),
        ], 180, 80);
        self::assertStringContainsString('1 0 0 1 10.000000 65.000000 Tm', $result->contentStream);
        self::assertStringContainsString('1 0 0 1 80.000000 65.000000 Tm', $result->contentStream);
    }

    #[DataProvider('invalidTextProvider')]
    public function testRejectsMalformedUtf8(string $text): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PlainTextCompiler())->compile([new PlainTextBlock($text, 0, 0, 80, 40)], 80, 40);
    }

    public static function invalidTextProvider(): iterable
    {
        yield 'malformed UTF-8' => ["\xc3\x28"];
        yield 'NUL' => ["a\0b"];
        yield 'escape' => ["a\x1bb"];
    }
}
