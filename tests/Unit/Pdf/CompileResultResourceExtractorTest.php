<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf;

use InvalidArgumentException;
use LibreSign\XObjectTemplate\Dto\CompileResult;
use LibreSign\XObjectTemplate\Pdf\CompileResultResourceExtractor;
use PHPUnit\Framework\TestCase;

final class CompileResultResourceExtractorTest extends TestCase
{
    public function testExtractReturnsEmptyArrayWhenResourceTypeIsMissing(): void
    {
        $extractor = new CompileResultResourceExtractor();
        $result = new CompileResult(
            contentStream: 'BT ET',
            resources: ['Font' => []],
            bbox: [0.0, 0.0, 40.0, 40.0],
        );

        self::assertSame([], $extractor->extract($result, 'XObject', 'XObject resource "%s" must be an array.'));
    }

    public function testExtractRejectsCollectionsWithNonStringAliases(): void
    {
        $extractor = new CompileResultResourceExtractor();
        $result = new CompileResult(
            contentStream: 'BT ET',
            resources: [
                'Font' => [
                    0 => [
                        'Type' => '/Font',
                    ],
                ],
            ],
            bbox: [0.0, 0.0, 40.0, 40.0],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Font resources must be an array.');

        $extractor->extract($result, 'Font', 'Font resource "%s" must be an array.');
    }

    public function testExtractRejectsResourceDictionariesWithNonStringKeys(): void
    {
        $extractor = new CompileResultResourceExtractor();
        $result = new CompileResult(
            contentStream: 'BT ET',
            resources: [
                'Font' => [
                    'F1' => [
                        0 => '/Font',
                    ],
                ],
            ],
            bbox: [0.0, 0.0, 40.0, 40.0],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Font resource "F1" must be an array.');

        $extractor->extract($result, 'Font', 'Font resource "%s" must be an array.');
    }

    public function testExtractReturnsNormalizedResourcesWhenInputIsValid(): void
    {
        $extractor = new CompileResultResourceExtractor();
        $result = new CompileResult(
            contentStream: 'BT ET',
            resources: [
                'Font' => [
                    'F1' => [
                        'Type' => '/Font',
                        'Subtype' => '/Type1',
                        'BaseFont' => '/Helvetica',
                    ],
                ],
            ],
            bbox: [0.0, 0.0, 40.0, 40.0],
        );

        self::assertSame(
            [
                'F1' => [
                    'Type' => '/Font',
                    'Subtype' => '/Type1',
                    'BaseFont' => '/Helvetica',
                ],
            ],
            $extractor->extract($result, 'Font', 'Font resource "%s" must be an array.'),
        );
    }
}
