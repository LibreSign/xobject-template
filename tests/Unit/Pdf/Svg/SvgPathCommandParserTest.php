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
            2.0,
            10.0,
            '/tmp/shape.svg',
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );

        self::assertStringContainsString('-2.000000 10.000000 m', $result);
        self::assertStringContainsString('8.000000 10.000000 l', $result);
        self::assertStringContainsString('13.000000 10.000000 l', $result);
        self::assertStringContainsString('13.000000 5.000000 l', $result);
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

    public function testConvertPathDataKeepsDistinctMoveCoordinates(): void
    {
        $parser = new SvgPathCommandParser();

        $result = $parser->convertPathData(
            'M 3 7 L 4 8',
            0.0,
            20.0,
            '/tmp/distinct-move.svg',
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );

        self::assertStringContainsString('3.000000 13.000000 m', $result);
        self::assertStringContainsString('4.000000 12.000000 l', $result);
    }

    public function testConvertPathDataSupportsCubicQuadraticAndArcCommands(): void
    {
        $parser = new SvgPathCommandParser();

        $result = $parser->convertPathData(
            'M 0 10 C 2 8 4 8 6 10 Q 8 12 10 10 T 14 10 A 4 2 0 0 1 18 10',
            2.0,
            20.0,
            '/tmp/curves.svg',
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );

        self::assertGreaterThanOrEqual(4, substr_count($result, ' c'));
        self::assertStringContainsString('-2.000000 10.000000 m', $result);
        self::assertStringContainsString('0.000000 12.000000 2.000000 12.000000 4.000000 10.000000 c', $result);
        self::assertStringContainsString('4.000000 10.000000 c', $result);
        self::assertStringContainsString('12.000000 10.000000 c', $result);
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

    public function testConvertPathDataParsesDistinctCubicControlPoints(): void
    {
        $parser = new SvgPathCommandParser();

        $result = $parser->convertPathData(
            'M 0 0 C 1 2 3 4 5 6',
            0.0,
            20.0,
            '/tmp/distinct-cubic.svg',
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );

        self::assertStringContainsString(
            '1.000000 18.000000 3.000000 16.000000 5.000000 14.000000 c',
            $result,
        );
    }

    public function testConvertPathDataParsesDistinctQuadraticControlPoint(): void
    {
        $parser = new SvgPathCommandParser();

        $result = $parser->convertPathData(
            'M 0 0 Q 3 5 7 11',
            0.0,
            20.0,
            '/tmp/distinct-quadratic.svg',
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );

        self::assertStringContainsString(
            '2.000000 16.666667 4.333333 13.000000 7.000000 9.000000 c',
            $result,
        );
    }

    public function testConvertPathDataParsesArcCurvePointsWithDistinctCoordinates(): void
    {
        $parser = new SvgPathCommandParser();

        $result = $parser->convertPathData(
            'M 0 10 A 6 4 0 0 1 12 10',
            0.0,
            20.0,
            '/tmp/distinct-arc.svg',
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );

        self::assertStringContainsString(
            '-0.000000 12.194335 2.708497 14.000000 6.000000 14.000000 c',
            $result,
        );
        self::assertStringContainsString(
            '9.291503 14.000000 12.000000 12.194335 12.000000 10.000000 c',
            $result,
        );
    }

    public function testConvertPathDataParsesArcFlagsAndSweepFromCorrectSlots(): void
    {
        $parser = new SvgPathCommandParser();

        $result = $parser->convertPathData(
            'M 2 3 A 7 5 2.5 0 1 0 9',
            0.0,
            20.0,
            '/tmp/distinct-arc-flags.svg',
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );

        self::assertStringContainsString(
            '1.007795 19.138991 3.354053 16.371429 2.470197 13.719862 c',
            $result,
        );
        self::assertStringContainsString(
            '1.586341 11.068294 -2.214523 9.472035 -5.949670 10.183747 c',
            $result,
        );

        $rotationSensitive = $parser->convertPathData(
            'M 2 3 A 7 5 1.2 0 1 0 9',
            0.0,
            20.0,
            '/tmp/distinct-arc-flags-rotation-sensitive.svg',
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );

        self::assertStringContainsString(
            '1.002032 19.139077 3.346601 16.397452 2.459942 13.737475 c',
            $rotationSensitive,
        );
        self::assertStringContainsString(
            '1.573283 11.077498 -2.230506 9.441463 -5.966404 10.113248 c',
            $rotationSensitive,
        );
    }

    public function testConvertPathDataAppliesMinXOffsetForMoveAndLineCommands(): void
    {
        $parser = new SvgPathCommandParser();

        $result = $parser->convertPathData(
            'M 10 10 L 12 8 H 14 V 6',
            5.0,
            20.0,
            '/tmp/minx-lines.svg',
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );

        self::assertStringContainsString('5.000000 10.000000 m', $result);
        self::assertStringContainsString('7.000000 12.000000 l', $result);
        self::assertStringContainsString('9.000000 12.000000 l', $result);
        self::assertStringContainsString('9.000000 14.000000 l', $result);
    }

    public function testConvertPathDataAppliesMinXOffsetForCubicAndQuadraticCommands(): void
    {
        $parser = new SvgPathCommandParser();

        $result = $parser->convertPathData(
            'M 10 10 C 11 9 12 8 13 7 Q 14 6 15 5',
            5.0,
            20.0,
            '/tmp/minx-curves.svg',
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );

        self::assertStringContainsString(
            '6.000000 11.000000 7.000000 12.000000 8.000000 13.000000 c',
            $result,
        );
        self::assertStringContainsString(
            '8.666667 13.666667 9.333333 14.333333 10.000000 15.000000 c',
            $result,
        );
    }

    public function testConvertPathDataAppliesMinXOffsetForArcCommands(): void
    {
        $parser = new SvgPathCommandParser();

        $result = $parser->convertPathData(
            'M 0 10 A 6 4 0 0 1 12 10',
            5.0,
            20.0,
            '/tmp/minx-arc.svg',
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );

        self::assertStringContainsString(
            '-5.000000 12.194335 -2.291503 14.000000 1.000000 14.000000 c',
            $result,
        );
        self::assertStringContainsString(
            '4.291503 14.000000 7.000000 12.194335 7.000000 10.000000 c',
            $result,
        );
    }

    public function testConvertPathDataSupportsSmoothQuadraticReflectionAfterPreviousQuadratic(): void
    {
        $parser = new SvgPathCommandParser();

        $result = $parser->convertPathData(
            'M 0 0 Q 2 2 4 0 T 8 0',
            0.0,
            10.0,
            '/tmp/smooth-quadratic-reflection.svg',
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );

        self::assertStringContainsString(
            '5.333333 11.333333 6.666667 11.333333 8.000000 10.000000 c',
            $result,
        );
    }

    public function testConvertPathDataResetsSmoothCubicStateAfterLineCommand(): void
    {
        $parser = new SvgPathCommandParser();

        $result = $parser->convertPathData(
            'M 0 0 C 2 2 4 2 6 0 L 8 0 S 10 2 12 0',
            0.0,
            10.0,
            '/tmp/smooth-cubic-after-line.svg',
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );

        self::assertStringContainsString(
            '8.000000 10.000000 10.000000 8.000000 12.000000 10.000000 c',
            $result,
        );
    }

    public function testConvertPathDataResetsSmoothCubicStateAfterArcCommand(): void
    {
        $parser = new SvgPathCommandParser();

        $result = $parser->convertPathData(
            'M 0 10 C 2 8 4 8 6 10 A 2 2 0 0 1 10 10 S 12 12 14 10',
            0.0,
            20.0,
            '/tmp/smooth-cubic-after-arc.svg',
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );

        self::assertStringContainsString(
            '10.000000 10.000000 12.000000 8.000000 14.000000 10.000000 c',
            $result,
        );
    }

    public function testConvertPathDataResetsSmoothQuadraticStateAfterLineCommand(): void
    {
        $parser = new SvgPathCommandParser();

        $result = $parser->convertPathData(
            'M 0 0 Q 2 2 4 0 L 5 0 T 7 2',
            0.0,
            10.0,
            '/tmp/smooth-quadratic-after-line.svg',
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );

        self::assertStringContainsString(
            '5.000000 10.000000 5.666667 9.333333 7.000000 8.000000 c',
            $result,
        );
    }

    public function testConvertPathDataResetsSmoothQuadraticStateAfterCubicCommand(): void
    {
        $parser = new SvgPathCommandParser();

        $result = $parser->convertPathData(
            'M 0 0 Q 2 2 4 0 C 5 1 6 1 7 0 T 9 2',
            0.0,
            10.0,
            '/tmp/smooth-quadratic-after-cubic.svg',
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );

        self::assertStringContainsString(
            '7.000000 10.000000 7.666667 9.333333 9.000000 8.000000 c',
            $result,
        );
    }

    public function testConvertPathDataResetsSmoothQuadraticStateAfterArcCommand(): void
    {
        $parser = new SvgPathCommandParser();

        $result = $parser->convertPathData(
            'M 0 10 Q 2 12 4 10 A 2 2 0 0 1 8 10 T 10 12',
            0.0,
            20.0,
            '/tmp/smooth-quadratic-after-arc.svg',
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );

        self::assertStringContainsString(
            '8.000000 10.000000 8.666667 9.333333 10.000000 8.000000 c',
            $result,
        );
    }

    public function testConvertPathDataResetsSmoothQuadraticStateAfterHorizontalCommand(): void
    {
        $parser = new SvgPathCommandParser();

        $result = $parser->convertPathData(
            'M 0 0 Q 2 2 4 0 H 5 T 7 2',
            0.0,
            10.0,
            '/tmp/smooth-quadratic-after-horizontal.svg',
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );

        self::assertStringContainsString(
            '5.000000 10.000000 5.666667 9.333333 7.000000 8.000000 c',
            $result,
        );
    }

    public function testConvertPathDataResetsSmoothQuadraticStateAfterVerticalCommand(): void
    {
        $parser = new SvgPathCommandParser();

        $result = $parser->convertPathData(
            'M 0 0 Q 2 2 4 0 V 1 T 6 3',
            0.0,
            10.0,
            '/tmp/smooth-quadratic-after-vertical.svg',
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );

        self::assertStringContainsString(
            '4.000000 9.000000 4.666667 8.333333 6.000000 7.000000 c',
            $result,
        );
    }

    public function testConvertPathDataResetsSmoothQuadraticStateAfterClosePath(): void
    {
        $parser = new SvgPathCommandParser();

        $result = $parser->convertPathData(
            'M 0 0 Q 2 2 4 0 Z T 6 2',
            0.0,
            10.0,
            '/tmp/smooth-quadratic-after-close.svg',
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );

        self::assertStringContainsString(
            '0.000000 10.000000 2.000000 9.333333 6.000000 8.000000 c',
            $result,
        );
    }

    public function testConvertPathDataAllowsFinalHorizontalCommandWithoutMalformedError(): void
    {
        $parser = new SvgPathCommandParser();

        $result = $parser->convertPathData(
            'M 0 0 H 5',
            0.0,
            10.0,
            '/tmp/final-horizontal.svg',
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );

        self::assertStringContainsString('5.000000 10.000000 l', $result);
    }

    public function testConvertPathDataAllowsFinalVerticalCommandWithoutMalformedError(): void
    {
        $parser = new SvgPathCommandParser();

        $result = $parser->convertPathData(
            'M 0 0 V 5',
            0.0,
            10.0,
            '/tmp/final-vertical.svg',
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );

        self::assertStringContainsString('0.000000 5.000000 l', $result);
    }

    public function testConvertPathDataAllowsFinalCubicCommandWithoutMalformedError(): void
    {
        $parser = new SvgPathCommandParser();

        $result = $parser->convertPathData(
            'M 0 0 C 1 2 3 4 5 6',
            0.0,
            20.0,
            '/tmp/final-cubic.svg',
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );

        self::assertStringContainsString(
            '1.000000 18.000000 3.000000 16.000000 5.000000 14.000000 c',
            $result,
        );
    }

    public function testConvertPathDataAllowsFinalQuadraticCommandWithoutMalformedError(): void
    {
        $parser = new SvgPathCommandParser();

        $result = $parser->convertPathData(
            'M 0 0 Q 3 5 7 11',
            0.0,
            20.0,
            '/tmp/final-quadratic.svg',
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );

        self::assertStringContainsString(
            '2.000000 16.666667 4.333333 13.000000 7.000000 9.000000 c',
            $result,
        );
    }

    public function testConvertPathDataUsesSubpathStartAsCurrentPointAfterClosePath(): void
    {
        $parser = new SvgPathCommandParser();

        $result = $parser->convertPathData(
            'M 1 1 L 3 1 Z l 1 0',
            0.0,
            10.0,
            '/tmp/relative-after-close.svg',
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );

        self::assertStringContainsString('1.000000 9.000000 m', $result);
        self::assertStringContainsString('3.000000 9.000000 l', $result);
        self::assertStringContainsString("h\n2.000000 9.000000 l", $result);
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
