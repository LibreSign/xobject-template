<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf\Svg;

use InvalidArgumentException;
use LibreSign\XObjectTemplate\Pdf\Svg\SvgPathCommandParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SvgPathCommandParserTest extends TestCase
{
    public function testConvertPathDataSupportsLineCommandsAndClosePath(): void
    {
        $parser = new SvgPathCommandParser();

        $result = $parser->convertPathData(
            'M 0 0 L 10 0 H 15 V 5 Z',
            0.0,
            10.0,
            '/tmp/shape.svg',
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );

        self::assertStringContainsString('0.000000 10.000000 m', $result);
        self::assertStringContainsString('10.000000 10.000000 l', $result);
        self::assertStringContainsString('15.000000 10.000000 l', $result);
        self::assertStringContainsString('15.000000 5.000000 l', $result);
        self::assertStringEndsWith('h', $result);
    }

    public function testConvertPathDataSupportsRelativeMoveImplicitLinesAndTransformMatrix(): void
    {
        $parser = new SvgPathCommandParser();

        $result = $parser->convertPathData(
            'm 1 1 2 0 0 2',
            0.0,
            20.0,
            '/tmp/relative.svg',
            [2.0, 0.0, 0.0, 2.0, 1.0, 3.0],
        );

        self::assertStringContainsString('3.000000 15.000000 m', $result);
        self::assertStringContainsString('7.000000 15.000000 l', $result);
        self::assertStringContainsString('7.000000 11.000000 l', $result);
    }

    public function testConvertPathDataSupportsCubicQuadraticAndArcCommands(): void
    {
        $parser = new SvgPathCommandParser();

        $result = $parser->convertPathData(
            'M 0 10 C 2 8 4 8 6 10 Q 8 12 10 10 T 14 10 A 4 2 0 0 1 18 10',
            0.0,
            20.0,
            '/tmp/curves.svg',
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );

        self::assertGreaterThanOrEqual(4, substr_count($result, ' c'));
        self::assertStringContainsString('0.000000 10.000000 m', $result);
        self::assertStringContainsString('6.000000 10.000000 c', $result);
        self::assertStringContainsString('14.000000 10.000000 c', $result);
    }

    public function testConvertPathDataSupportsSmoothCurveCommandsWithoutPreviousControlPoints(): void
    {
        $parser = new SvgPathCommandParser();

        $result = $parser->convertPathData(
            'M 2 2 S 4 4 6 2 T 10 2',
            0.0,
            12.0,
            '/tmp/smooth.svg',
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );

        self::assertStringContainsString('2.000000 10.000000 m', $result);
        self::assertStringContainsString('2.000000 10.000000 4.000000 8.000000 6.000000 10.000000 c', $result);
        self::assertStringContainsString('6.000000 10.000000 7.333333 10.000000 10.000000 10.000000 c', $result);
    }

    public function testConvertPathDataSupportsRelativeCommandsScientificNotationAndRelativeArc(): void
    {
        $parser = new SvgPathCommandParser();

        $result = $parser->convertPathData(
            'M 1e1 1e1 l -5 0 h 2 v -3 a 4 2 0 0 1 6 0',
            0.0,
            20.0,
            '/tmp/relative-arc.svg',
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );

        self::assertStringContainsString('10.000000 10.000000 m', $result);
        self::assertStringContainsString('5.000000 10.000000 l', $result);
        self::assertStringContainsString('7.000000 10.000000 l', $result);
        self::assertStringContainsString('7.000000 13.000000 l', $result);
        self::assertGreaterThanOrEqual(2, substr_count($result, ' c'));
    }

    public function testConvertPathDataNormalizesNegativeArcRadii(): void
    {
        $parser = new SvgPathCommandParser();

        $withPositiveRadii = $parser->convertPathData(
            'M 0 10 A 4 2 0 0 1 8 10',
            0.0,
            20.0,
            '/tmp/positive-radius.svg',
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );
        $withNegativeRadii = $parser->convertPathData(
            'M 0 10 A -4 -2 0 0 1 8 10',
            0.0,
            20.0,
            '/tmp/negative-radius.svg',
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );

        self::assertSame($withPositiveRadii, $withNegativeRadii);
    }

    public function testConvertPathDataCastsArcFlagsToIntegersBeforeConversion(): void
    {
        $parser = new SvgPathCommandParser();

        $withIntegerFlags = $parser->convertPathData(
            'M 0 10 A 4 2 0 1 0 8 10',
            0.0,
            20.0,
            '/tmp/integer-flags.svg',
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );
        $withDecimalFlags = $parser->convertPathData(
            'M 0 10 A 4 2 0 1.9 0.2 8 10',
            0.0,
            20.0,
            '/tmp/decimal-flags.svg',
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );

        self::assertSame($withIntegerFlags, $withDecimalFlags);
    }

    public function testConvertPathDataSupportsSmoothCubicReflectionAfterPreviousCubic(): void
    {
        $parser = new SvgPathCommandParser();

        $result = $parser->convertPathData(
            'M 0 0 C 2 2 4 2 6 0 S 10 -2 12 0',
            0.0,
            10.0,
            '/tmp/smooth-cubic.svg',
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );

        self::assertStringContainsString('6.000000 10.000000 c', $result);
        self::assertStringContainsString('8.000000 12.000000 10.000000 12.000000 12.000000 10.000000 c', $result);
    }

    #[DataProvider('provideInvalidPathScenarios')]
    public function testConvertPathDataRejectsInvalidSequences(string $pathData, string $expectedMessage): void
    {
        $parser = new SvgPathCommandParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        $parser->convertPathData(
            $pathData,
            0.0,
            10.0,
            '/tmp/invalid.svg',
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );
    }

    /**
     * @return iterable<string, array{pathData: string, expectedMessage: string}>
     */
    public static function provideInvalidPathScenarios(): iterable
    {
        yield 'empty path data' => [
            'pathData' => '',
            'expectedMessage' => 'Unsupported or empty SVG path data in "/tmp/invalid.svg".',
        ];

        yield 'path without initial command' => [
            'pathData' => '1 2 3',
            'expectedMessage' => 'Invalid SVG path command sequence in "/tmp/invalid.svg".',
        ];

        yield 'malformed move command' => [
            'pathData' => 'M 1',
            'expectedMessage' => 'Malformed SVG path data in "/tmp/invalid.svg".',
        ];

        yield 'unsupported command' => [
            'pathData' => 'M 1 1 R 2 2',
            'expectedMessage' => 'SVG path command "R" is not supported for source "/tmp/invalid.svg".',
        ];
    }
}
