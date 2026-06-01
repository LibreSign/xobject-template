<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Documentation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExamplesTest extends TestCase
{
    /**
     * @return list<string>
     */
    public static function documentedExamples(): array
    {
        return [
            'basic-template.php',
            'preview-pdf.php',
            'images.php',
            'svg.php',
            'placement.php',
            'interpolation.php',
        ];
    }

    /**
     * @return iterable<string, array{exampleFile: string}>
     */
    public static function exampleProvider(): iterable
    {
        foreach (self::documentedExamples() as $exampleFile) {
            yield $exampleFile => ['exampleFile' => $exampleFile];
        }
    }

    #[DataProvider('exampleProvider')]
    public function testExampleScriptsExecuteAndGenerateExpectedArtifacts(string $exampleFile): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $examplePath = $projectRoot . '/examples/' . $exampleFile;
        self::assertFileExists($examplePath);

        $result = require $examplePath;

        self::assertIsArray($result);
        self::assertArrayHasKey('generated_files', $result);
        self::assertArrayHasKey('content_stream', $result);
        self::assertArrayHasKey('resources', $result);
        self::assertArrayHasKey('bbox', $result);

        self::assertIsString($result['content_stream']);
        self::assertNotSame('', trim((string) $result['content_stream']));

        self::assertIsArray($result['resources']);
        self::assertArrayHasKey('Font', $result['resources']);

        self::assertIsArray($result['bbox']);
        self::assertCount(4, $result['bbox']);

        /** @var list<string> $generatedFiles */
        $generatedFiles = $result['generated_files'];
        self::assertNotSame([], $generatedFiles);

        foreach ($generatedFiles as $generatedFile) {
            self::assertFileExists($generatedFile);
            $contents = file_get_contents($generatedFile);
            self::assertIsString($contents);
            self::assertNotSame('', $contents);

            if (str_ends_with($generatedFile, '.pdf')) {
                self::assertStringStartsWith('%PDF-', $contents);
                self::assertStringContainsString('%%EOF', $contents);
            }
        }

        if (isset($result['pdf_file']) && is_string($result['pdf_file'])) {
            $pdfContents = file_get_contents($result['pdf_file']);
            self::assertIsString($pdfContents);
            self::assertStringStartsWith('%PDF-', $pdfContents);
        }
    }

    public function testEveryPhpExampleFileIsCoveredByTheProvider(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $allPhpExamples = glob($projectRoot . '/examples/*.php');
        self::assertIsArray($allPhpExamples);

        $actual = array_map(static fn (string $path): string => basename($path), $allPhpExamples);
        sort($actual);

        $expected = self::documentedExamples();
        sort($expected);

        self::assertSame($expected, $actual);
    }
}
