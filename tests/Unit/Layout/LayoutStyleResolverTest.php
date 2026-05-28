<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Layout;

use LibreSign\XObjectTemplate\Css\InlineStyleParser;
use LibreSign\XObjectTemplate\Layout\LayoutStyleResolver;
use PHPUnit\Framework\TestCase;

final class LayoutStyleResolverTest extends TestCase
{
    public function testToPointsAndRelativeDimensionsNormalizeTrimmedUppercaseValues(): void
    {
        $resolver = new LayoutStyleResolver();

        self::assertSame(7.5, $resolver->toPoints('10PX'));
        self::assertSame(10.0, $resolver->toPoints('10'));
        self::assertSame(20.0, $resolver->resolveRelativeDimension(' 25% ', 80.0));
        self::assertSame(7.5, $resolver->resolveRelativeDimension(' 10PX ', 80.0));
        self::assertSame(0.0, $resolver->resolveRelativeDimension(' ', 80.0));
    }

    public function testParseBoxSpacingRelativeUsesAxisSpecificReferencesAcrossShorthandVariants(): void
    {
        $resolver = new LayoutStyleResolver();

        self::assertSame(
            ['top' => 10.0, 'right' => 20.0, 'bottom' => 10.0, 'left' => 20.0],
            $resolver->parseBoxSpacingRelative('10%', 200.0, 100.0),
        );
        self::assertSame(
            ['top' => 10.0, 'right' => 40.0, 'bottom' => 10.0, 'left' => 40.0],
            $resolver->parseBoxSpacingRelative('10% 20%', 200.0, 100.0),
        );
        self::assertSame(
            ['top' => 10.0, 'right' => 40.0, 'bottom' => 30.0, 'left' => 40.0],
            $resolver->parseBoxSpacingRelative('10% 20% 30%', 200.0, 100.0),
        );
        self::assertSame(
            ['top' => 10.0, 'right' => 40.0, 'bottom' => 30.0, 'left' => 80.0],
            $resolver->parseBoxSpacingRelative('10% 20% 30% 40%', 200.0, 100.0),
        );
    }

    public function testParseBoxSpacingRelativeReturnsZeroSlotsForWhitespaceOnlyInput(): void
    {
        $resolver = new LayoutStyleResolver();

        self::assertSame(
            ['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0],
            $resolver->parseBoxSpacingRelative(" \t\n ", 200.0, 100.0),
        );
    }

    public function testPositionAndFontResolutionNormalizeWhitespaceAndCase(): void
    {
        $parser = new InlineStyleParser();
        $resolver = new LayoutStyleResolver();

        self::assertTrue($resolver->isAbsolutelyPositioned($parser->parse('position: ABSOLUTE ')));
        self::assertFalse($resolver->isAbsolutelyPositioned($parser->parse('position: relative')));
        self::assertSame('F6', $resolver->resolveFontAlias('Courier New', '600'));
        self::assertSame('F4', $resolver->resolveFontAlias('Times New Roman', 'BOLD'));
        self::assertSame('F1', $resolver->resolveFontAlias('Helvetica', '500'));
    }
}
