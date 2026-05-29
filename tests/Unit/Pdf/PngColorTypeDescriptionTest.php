<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf;

use InvalidArgumentException;
use LibreSign\XObjectTemplate\Pdf\PngColorTypeDescription;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PngColorTypeDescriptionTest extends TestCase
{
    #[DataProvider('provideSupportedColorLayouts')]
    public function testConstructorAcceptsSupportedColorLayouts(
        string $colorSpace,
        int $colorCount,
        int $bytesPerPixel,
        bool $hasAlpha,
    ): void {
        $description = new PngColorTypeDescription($colorSpace, $colorCount, $bytesPerPixel, $hasAlpha);

        self::assertSame($colorSpace, $description->colorSpace);
        self::assertSame($colorCount, $description->colorCount);
        self::assertSame($bytesPerPixel, $description->bytesPerPixel);
        self::assertSame($hasAlpha, $description->hasAlpha);
    }

    /**
     * @return iterable<string, array{0: string, 1: int, 2: int, 3: bool}>
     */
    public static function provideSupportedColorLayouts(): iterable
    {
        yield 'grayscale' => ['/DeviceGray', 1, 1, false];
        yield 'rgb' => ['/DeviceRGB', 3, 3, false];
        yield 'grayscale with alpha' => ['/DeviceGray', 1, 2, true];
        yield 'rgb with alpha' => ['/DeviceRGB', 3, 4, true];
    }

    #[DataProvider('provideInvalidColorLayouts')]
    public function testConstructorRejectsInvalidColorLayouts(
        int $colorCount,
        int $bytesPerPixel,
        bool $hasAlpha,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new PngColorTypeDescription('/DeviceRGB', $colorCount, $bytesPerPixel, $hasAlpha);
    }

    /**
     * @return iterable<string, array{0: int, 1: int, 2: bool, 3: string}>
     */
    public static function provideInvalidColorLayouts(): iterable
    {
        yield 'unsupported color count' => [2, 2, false, 'PNG color count must be 1 or 3.'];
        yield 'non-positive bytes per pixel' => [1, 0, false, 'PNG bytes per pixel must be positive.'];
        yield 'opaque layout mismatch' => [1, 2, false, 'PNG color layout is inconsistent.'];
        yield 'alpha layout mismatch' => [3, 3, true, 'PNG color layout is inconsistent.'];
    }
}
