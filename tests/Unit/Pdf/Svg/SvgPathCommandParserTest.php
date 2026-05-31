<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf\Svg;

use InvalidArgumentException;
use LibreSign\XObjectTemplate\Pdf\Svg\SvgPathCommandParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-type BasicScenario array{
 *     pathData: string,
 *     minX: float,
 *     maxY: float,
 *     source: string,
 *     expectedSnippets: list<string>,
 *     unexpectedSnippets?: list<string>
 * }
 * @phpstan-type TransformScenario array{
 *     pathData: string,
 *     minX: float,
 *     maxY: float,
 *     transformMatrix: array,
 *     source: string,
 *     expectedSnippets: list<string>
 * }
 * @phpstan-type CurveScenario array{
 *     pathData: string,
 *     maxY: float,
 *     expectedSnippets: list<string>,
 *     expectedCurveCount?: int
 * }
 */
final class SvgPathCommandParserTest extends TestCase
{
    #[DataProvider('provideBasicPathConversionScenarios')]
    public function testConvertPathDataHandlesBasicCommands(
        string $pathData,
        float $minX,
        float $maxY,
        string $source,
        array $expectedSnippets,
        array $unexpectedSnippets = [],
    ): void {
        $parser = new SvgPathCommandParser();

        $result = $parser->convertPathData(
            $pathData,
            $minX,
            $maxY,
            $source,
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );

        foreach ($expectedSnippets as $snippet) {
            self::assertStringContainsString($snippet, $result);
        }

        foreach ($unexpectedSnippets as $snippet) {
            self::assertStringNotContainsString($snippet, $result);
        }
    }

    #[DataProvider('provideTransformAndCoordinateConversionScenarios')]
    public function testConvertPathDataAppliesTransformationAndCoordinateSystem(
        string $pathData,
        float $minX,
        float $maxY,
        array $transformMatrix,
        string $source,
        array $expectedSnippets,
    ): void {
        $parser = new SvgPathCommandParser();

        $result = $parser->convertPathData(
            $pathData,
            $minX,
            $maxY,
            $source,
            $transformMatrix,
        );

        foreach ($expectedSnippets as $snippet) {
            self::assertStringContainsString($snippet, $result);
        }
    }

    #[DataProvider('provideCurveAndArcConversionScenarios')]
    public function testConvertPathDataHandlesComplexCurvesAndArcs(
        string $pathData,
        float $maxY,
        array $expectedSnippets,
        int $expectedCurveCount = null,
    ): void {
        $parser = new SvgPathCommandParser();

        $result = $parser->convertPathData(
            $pathData,
            0.0,
            $maxY,
            '/tmp/test.svg',
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );

        foreach ($expectedSnippets as $snippet) {
            self::assertStringContainsString($snippet, $result);
        }

        if ($expectedCurveCount !== null) {
            self::assertGreaterThanOrEqual(
                $expectedCurveCount,
                substr_count($result, ' c'),
                'Expected at least ' . $expectedCurveCount . ' cubic curves',
            );
        }
    }

    #[DataProvider('provideArcNormalizationScenarios')]
    public function testConvertPathDataNormalizesArcParameters(
        string $pathData1,
        string $pathData2,
        string $description,
    ): void {
        $parser = new SvgPathCommandParser();

        $result1 = $parser->convertPathData(
            $pathData1,
            0.0,
            20.0,
            '/tmp/variant1.svg',
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );

        $result2 = $parser->convertPathData(
            $pathData2,
            0.0,
            20.0,
            '/tmp/variant2.svg',
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );

        self::assertSame($result1, $result2, sprintf('Arc normalization failed for %s', $description));
    }

    #[DataProvider('provideSmoothCubicResetScenarios')]
    public function testConvertPathDataResetsSmoothCubicStateAfterStateBreakingCommands(
        string $pathData,
        array $expectedSnippets,
        string $description,
    ): void {
        $parser = new SvgPathCommandParser();

        $result = $parser->convertPathData(
            $pathData,
            0.0,
            10.0,
            '/tmp/smooth-cubic-state.svg',
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );

        foreach ($expectedSnippets as $snippet) {
            self::assertStringContainsString($snippet, $result, $description);
        }
    }

    #[DataProvider('provideSmoothQuadraticResetScenarios')]
    public function testConvertPathDataResetsSmoothQuadraticStateAfterStateBreakingCommands(
        string $pathData,
        float $height,
        string $source,
        string $expectedSnippet,
    ): void {
        $parser = new SvgPathCommandParser();

        $result = $parser->convertPathData(
            $pathData,
            0.0,
            $height,
            $source,
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );

        self::assertStringContainsString($expectedSnippet, $result);
    }

    #[DataProvider('provideFinalCommandScenarios')]
    public function testConvertPathDataAllowsFinalCommandWithoutMalformedError(
        string $pathData,
        float $height,
        string $source,
        string $expectedSnippet,
    ): void {
        $parser = new SvgPathCommandParser();

        $result = $parser->convertPathData(
            $pathData,
            0.0,
            $height,
            $source,
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );

        self::assertStringContainsString($expectedSnippet, $result);
    }

    #[DataProvider('provideRelativeAfterClosePathScenarios')]
    public function testConvertPathDataUsesSubpathStartAsCurrentPointAfterClosePath(
        string $pathData,
        array $expectedSnippets,
        string $description,
    ): void {
        $parser = new SvgPathCommandParser();

        $result = $parser->convertPathData(
            $pathData,
            0.0,
            10.0,
            '/tmp/relative-after-close.svg',
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );

        foreach ($expectedSnippets as $snippet) {
            self::assertStringContainsString($snippet, $result, $description);
        }
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

    public function testConvertPathDataRejectsTrailingNumbersAfterClosePath(): void
    {
        $parser = new SvgPathCommandParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Malformed SVG path data in "/tmp/invalid.svg".');

        $parser->convertPathData(
            'M 0 0 Z 1',
            0.0,
            10.0,
            '/tmp/invalid.svg',
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );
    }

    public function testConvertPathDataSubtractsMinXFromFirstCubicControlPoint(): void
    {
        $parser = new SvgPathCommandParser();

        $result = $parser->convertPathData(
            'M 10 10 C 11 9 12 8 13 7',
            5.0,
            20.0,
            '/tmp/cubic-minx.svg',
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        );

        self::assertStringContainsString('6.000000 11.000000 7.000000 12.000000 8.000000 13.000000 c', $result);
        self::assertStringNotContainsString('16.000000 11.000000 7.000000 12.000000 8.000000 13.000000 c', $result);
    }

    /**
     * @return iterable<string, BasicScenario>
     */
    public static function provideBasicPathConversionScenarios(): iterable
    {
        yield 'line commands and close path with minX offset' => [
            'pathData' => 'M 0 0 L 10 0 H 15 V 5 Z',
            'minX' => 2.0,
            'maxY' => 10.0,
            'source' => '/tmp/shape.svg',
            'expectedSnippets' => [
                '-2.000000 10.000000 m',
                '8.000000 10.000000 l',
                '13.000000 10.000000 l',
                '13.000000 5.000000 l',
            ],
            'unexpectedSnippets' => [],
        ];

        yield 'relative move with implicit lines and transform matrix' => [
            'pathData' => 'm 1 1 2 0 0 2',
            'minX' => 0.0,
            'maxY' => 20.0,
            'source' => '/tmp/relative.svg',
            'expectedSnippets' => [
                '1.000000 19.000000 m',
                '3.000000 19.000000 l',
                '3.000000 17.000000 l',
            ],
        ];

        yield 'distinct move and line coordinates preserved' => [
            'pathData' => 'M 3 7 L 4 8',
            'minX' => 0.0,
            'maxY' => 20.0,
            'source' => '/tmp/distinct-move.svg',
            'expectedSnippets' => [
                '3.000000 13.000000 m',
                '4.000000 12.000000 l',
            ],
        ];

        yield 'relative commands with scientific notation' => [
            'pathData' => 'M 1e1 1e1 l -5 0 h 2 v -3',
            'minX' => 0.0,
            'maxY' => 20.0,
            'source' => '/tmp/scientific.svg',
            'expectedSnippets' => [
                '10.000000 10.000000 m',
                '5.000000 10.000000 l',
                '7.000000 10.000000 l',
                '7.000000 13.000000 l',
            ],
        ];

        yield 'smooth cubic without previous control point' => [
            'pathData' => 'M 2 2 S 4 4 6 2 T 10 2',
            'minX' => 0.0,
            'maxY' => 12.0,
            'source' => '/tmp/smooth.svg',
            'expectedSnippets' => [
                '2.000000 10.000000 m',
                '2.000000 10.000000 4.000000 8.000000 6.000000 10.000000 c',
                '6.000000 10.000000 7.333333 10.000000 10.000000 10.000000 c',
            ],
        ];

        yield 'smooth cubic after previous cubic' => [
            'pathData' => 'M 0 0 C 2 2 4 2 6 0 S 10 -2 12 0',
            'minX' => 0.0,
            'maxY' => 10.0,
            'source' => '/tmp/smooth-cubic.svg',
            'expectedSnippets' => [
                '6.000000 10.000000 c',
                '8.000000 12.000000 10.000000 12.000000 12.000000 10.000000 c',
            ],
        ];

        yield 'smooth quadratic after previous quadratic' => [
            'pathData' => 'M 0 0 Q 2 2 4 0 T 8 0',
            'minX' => 0.0,
            'maxY' => 10.0,
            'source' => '/tmp/smooth-quadratic-reflection.svg',
            'expectedSnippets' => [
                '5.333333 11.333333 6.666667 11.333333 8.000000 10.000000 c',
            ],
        ];
    }

    /**
     * @return iterable<string, TransformScenario>
     */
    public static function provideTransformAndCoordinateConversionScenarios(): iterable
    {
        yield 'relative move with transform matrix applied' => [
            'pathData' => 'm 1 1 2 0 0 2',
            'minX' => 0.0,
            'maxY' => 20.0,
            'transformMatrix' => [2.0, 0.0, 0.0, 2.0, 1.0, 3.0],
            'source' => '/tmp/transform.svg',
            'expectedSnippets' => [
                '3.000000 15.000000 m',
                '7.000000 15.000000 l',
                '7.000000 11.000000 l',
            ],
        ];

        yield 'minx offset for move and line commands' => [
            'pathData' => 'M 10 10 L 12 8 H 14 V 6',
            'minX' => 5.0,
            'maxY' => 20.0,
            'transformMatrix' => [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
            'source' => '/tmp/minx-lines.svg (via transform)',
            'expectedSnippets' => [
                '5.000000 10.000000 m',
                '7.000000 12.000000 l',
                '9.000000 12.000000 l',
                '9.000000 14.000000 l',
            ],
        ];

        yield 'minx offset for cubic and quadratic commands' => [
            'pathData' => 'M 10 10 C 11 9 12 8 13 7 Q 14 6 15 5',
            'minX' => 5.0,
            'maxY' => 20.0,
            'transformMatrix' => [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
            'source' => '/tmp/minx-curves.svg (via transform)',
            'expectedSnippets' => [
                '6.000000 11.000000 7.000000 12.000000 8.000000 13.000000 c',
                '8.666667 13.666667 9.333333 14.333333 10.000000 15.000000 c',
            ],
        ];

        yield 'minx offset for arc commands' => [
            'pathData' => 'M 0 10 A 6 4 0 0 1 12 10',
            'minX' => 5.0,
            'maxY' => 20.0,
            'transformMatrix' => [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
            'source' => '/tmp/minx-arc.svg (via transform)',
            'expectedSnippets' => [
                '-5.000000 12.194335 -2.291503 14.000000 1.000000 14.000000 c',
                '4.291503 14.000000 7.000000 12.194335 7.000000 10.000000 c',
            ],
        ];
    }

    /**
     * @return iterable<string, CurveScenario>
     */
    public static function provideCurveAndArcConversionScenarios(): iterable
    {
        yield 'cubic, quadratic, and arc commands mixed' => [
            'pathData' => 'M 0 10 C 2 8 4 8 6 10 Q 8 12 10 10 T 14 10 A 4 2 0 0 1 18 10',
            'maxY' => 20.0,
            'expectedSnippets' => [
                '0.000000 10.000000 m',
                '2.000000 12.000000 4.000000 12.000000 6.000000 10.000000 c',
                '7.333333 8.666667 8.666667 8.666667 10.000000 10.000000 c',
                '11.333333 11.333333 12.666667 11.333333 14.000000 10.000000 c',
            ],
            'expectedCurveCount' => 4,
        ];

        yield 'distinct cubic control points' => [
            'pathData' => 'M 0 0 C 1 2 3 4 5 6',
            'maxY' => 20.0,
            'expectedSnippets' => [
                '1.000000 18.000000 3.000000 16.000000 5.000000 14.000000 c',
            ],
        ];

        yield 'distinct quadratic control point' => [
            'pathData' => 'M 0 0 Q 3 5 7 11',
            'maxY' => 20.0,
            'expectedSnippets' => [
                '2.000000 16.666667 4.333333 13.000000 7.000000 9.000000 c',
            ],
        ];

        yield 'arc curve points with distinct coordinates' => [
            'pathData' => 'M 0 10 A 6 4 0 0 1 12 10',
            'maxY' => 20.0,
            'expectedSnippets' => [
                '-0.000000 12.194335 2.708497 14.000000 6.000000 14.000000 c',
                '9.291503 14.000000 12.000000 12.194335 12.000000 10.000000 c',
            ],
        ];

        yield 'arc flags and sweep from correct slots with rotation' => [
            'pathData' => 'M 2 3 A 7 5 2.5 0 1 0 9',
            'maxY' => 20.0,
            'expectedSnippets' => [
                '1.007795 19.138991 3.354053 16.371429 2.470197 13.719862 c',
                '1.586341 11.068294 3.735147 10.288287 0.000000 11.000000 c',
            ],
        ];

        yield 'arc rotation sensitivity affects control point calculation' => [
            'pathData' => 'M 2 3 A 7 5 1.2 0 1 0 9',
            'maxY' => 20.0,
            'expectedSnippets' => [
                '1.002032 19.139077 3.346601 16.397452 2.459942 13.737475 c',
                '1.573283 11.077498 3.735898 10.328215 0.000000 11.000000 c',
            ],
        ];

        yield 'relative arc command in complex path' => [
            'pathData' => 'M 1e1 1e1 l -5 0 h 2 v -3 a 4 2 0 0 1 6 0',
            'maxY' => 20.0,
            'expectedSnippets' => [
                '10.000000 10.000000 m',
                '5.000000 10.000000 l',
                '7.000000 10.000000 l',
                '7.000000 13.000000 l',
            ],
            'expectedCurveCount' => 2,
        ];
    }

    /**
     * @return iterable<string, array{pathData1: string, pathData2: string, description: string}>
     */
    public static function provideArcNormalizationScenarios(): iterable
    {
        yield 'negative arc radii normalized to positive' => [
            'pathData1' => 'M 0 10 A 4 2 0 0 1 8 10',
            'pathData2' => 'M 0 10 A -4 -2 0 0 1 8 10',
            'description' => 'negative radius normalization',
        ];

        yield 'decimal arc flags cast to integers' => [
            'pathData1' => 'M 0 10 A 4 2 0 1 0 8 10',
            'pathData2' => 'M 0 10 A 4 2 0 1.9 0.2 8 10',
            'description' => 'arc flag decimal casting',
        ];

        yield 'both negative radii and decimal flags normalized together' => [
            'pathData1' => 'M 0 10 A 5 3 0 0 1 10 10',
            'pathData2' => 'M 0 10 A -5 -3 0 0.1 1.9 10 10',
            'description' => 'combined arc normalization',
        ];
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

        yield 'malformed arc command missing endpoint' => [
            'pathData' => 'M 0 0 A 1 2 0 0 1',
            'expectedMessage' => 'Malformed SVG path data in "/tmp/invalid.svg".',
        ];

        yield 'trailing scalar after close command' => [
            'pathData' => 'M 0 0 Z 1',
            'expectedMessage' => 'Malformed SVG path data in "/tmp/invalid.svg".',
        ];
    }

    /**
     * @return iterable<string, array{pathData: string, expectedSnippets: list<string>, description: string}>
     */
    public static function provideSmoothCubicResetScenarios(): iterable
    {
        yield 'resets smooth cubic state after line command' => [
            'pathData' => 'M 0 0 C 2 2 4 2 6 0 L 8 0 S 10 2 12 0',
            'expectedSnippets' => [
                '8.000000 10.000000 10.000000 8.000000 12.000000 10.000000 c',
            ],
            'description' => 'S after L should not use previous cubic endpoint as control point',
        ];

        yield 'resets smooth cubic state after arc command' => [
            'pathData' => 'M 0 10 C 2 8 4 8 6 10 A 2 2 0 0 1 10 10 S 12 12 14 10',
            'expectedSnippets' => [
                '10.000000 0.000000 12.000000 -2.000000 14.000000 0.000000 c',
            ],
            'description' => 'S after A should not use previous cubic control point as reflection',
        ];

        yield 'maintains smooth cubic state across relative and absolute commands' => [
            'pathData' => 'M 0 0 c 2 2 4 2 6 0 s 4 -2 6 0',
            'expectedSnippets' => [
                '2.000000 8.000000 4.000000 8.000000 6.000000 10.000000 c',
                '8.000000 12.000000 10.000000 12.000000 12.000000 10.000000 c',
            ],
            'description' => 'relative and absolute smooth cubics should maintain control point state',
        ];
    }

    /**
     * @return iterable<string, array{pathData: string, height: float, source: string, expectedSnippet: string}>
     */
    public static function provideSmoothQuadraticResetScenarios(): iterable
    {
        yield 'after line command' => [
            'pathData' => 'M 0 0 Q 2 2 4 0 L 5 0 T 7 2',
            'height' => 10.0,
            'source' => '/tmp/smooth-quadratic-after-line.svg',
            'expectedSnippet' => '5.000000 10.000000 5.666667 9.333333 7.000000 8.000000 c',
        ];

        yield 'after cubic command' => [
            'pathData' => 'M 0 0 Q 2 2 4 0 C 5 1 6 1 7 0 T 9 2',
            'height' => 10.0,
            'source' => '/tmp/smooth-quadratic-after-cubic.svg',
            'expectedSnippet' => '7.000000 10.000000 7.666667 9.333333 9.000000 8.000000 c',
        ];

        yield 'after arc command' => [
            'pathData' => 'M 0 10 Q 2 12 4 10 A 2 2 0 0 1 8 10 T 10 12',
            'height' => 20.0,
            'source' => '/tmp/smooth-quadratic-after-arc.svg',
            'expectedSnippet' => '8.000000 10.000000 8.666667 9.333333 10.000000 8.000000 c',
        ];

        yield 'after horizontal command' => [
            'pathData' => 'M 0 0 Q 2 2 4 0 H 5 T 7 2',
            'height' => 10.0,
            'source' => '/tmp/smooth-quadratic-after-horizontal.svg',
            'expectedSnippet' => '5.000000 10.000000 5.666667 9.333333 7.000000 8.000000 c',
        ];

        yield 'after vertical command' => [
            'pathData' => 'M 0 0 Q 2 2 4 0 V 1 T 6 3',
            'height' => 10.0,
            'source' => '/tmp/smooth-quadratic-after-vertical.svg',
            'expectedSnippet' => '4.000000 9.000000 4.666667 8.333333 6.000000 7.000000 c',
        ];

        yield 'after close path command' => [
            'pathData' => 'M 0 0 Q 2 2 4 0 Z T 6 2',
            'height' => 10.0,
            'source' => '/tmp/smooth-quadratic-after-close.svg',
            'expectedSnippet' => '0.000000 10.000000 2.000000 9.333333 6.000000 8.000000 c',
        ];
    }

    /**
     * @return iterable<string, array{pathData: string, height: float, source: string, expectedSnippet: string}>
     */
    public static function provideFinalCommandScenarios(): iterable
    {
        yield 'final horizontal command' => [
            'pathData' => 'M 0 0 H 5',
            'height' => 10.0,
            'source' => '/tmp/final-horizontal.svg',
            'expectedSnippet' => '5.000000 10.000000 l',
        ];

        yield 'final vertical command' => [
            'pathData' => 'M 0 0 V 5',
            'height' => 10.0,
            'source' => '/tmp/final-vertical.svg',
            'expectedSnippet' => '0.000000 5.000000 l',
        ];

        yield 'final cubic command' => [
            'pathData' => 'M 0 0 C 1 2 3 4 5 6',
            'height' => 20.0,
            'source' => '/tmp/final-cubic.svg',
            'expectedSnippet' => '1.000000 18.000000 3.000000 16.000000 5.000000 14.000000 c',
        ];

        yield 'final quadratic command' => [
            'pathData' => 'M 0 0 Q 3 5 7 11',
            'height' => 20.0,
            'source' => '/tmp/final-quadratic.svg',
            'expectedSnippet' => '2.000000 16.666667 4.333333 13.000000 7.000000 9.000000 c',
        ];

        yield 'final smooth quadratic command' => [
            'pathData' => 'M 0 0 Q 2 2 4 0 T 8 0',
            'height' => 10.0,
            'source' => '/tmp/final-smooth-quadratic.svg',
            'expectedSnippet' => '5.333333 11.333333 6.666667 11.333333 8.000000 10.000000 c',
        ];

        yield 'final arc command' => [
            'pathData' => 'M 0 10 A 6 4 0 0 1 12 10',
            'height' => 20.0,
            'source' => '/tmp/final-arc.svg',
            'expectedSnippet' => '9.291503 14.000000 12.000000 12.194335 12.000000 10.000000 c',
        ];
    }

    /**
     * @return iterable<string, array{pathData: string, expectedSnippets: list<string>, description: string}>
     */
    public static function provideRelativeAfterClosePathScenarios(): iterable
    {
        yield 'relative line after close path uses subpath start' => [
            'pathData' => 'M 1 1 L 3 1 Z l 1 0',
            'expectedSnippets' => [
                '1.000000 9.000000 m',
                '3.000000 9.000000 l',
                '2.000000 9.000000 l',
            ],
            'description' => 'current point should reset to subpath start after Z',
        ];

        yield 'multiple subpaths with close and relative commands' => [
            'pathData' => 'M 0 0 L 5 0 Z m 2 2 l 3 0',
            'expectedSnippets' => [
                '0.000000 10.000000 m',
                '5.000000 10.000000 l',
                '2.000000 8.000000 m',
                '5.000000 8.000000 l',
            ],
            'description' => 'new subpath should start independently',
        ];

        yield 'close path resets control points for smooth commands' => [
            'pathData' => 'M 0 0 C 2 2 4 2 6 0 Z S 8 0 10 2',
            'expectedSnippets' => [
                '6.000000 10.000000 c',
                '0.000000 10.000000 8.000000 10.000000 10.000000 8.000000 c',
            ],
            'description' => 'S after Z should treat previous curve as non-existent',
        ];
    }
}
