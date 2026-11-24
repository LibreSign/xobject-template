<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Integration;

use LibreSign\XObjectTemplate\Dto\PlainTextBlock;
use LibreSign\XObjectTemplate\Pdf\SinglePagePdfExporter;
use LibreSign\XObjectTemplate\Pdf\TemplateDocumentBuilder;
use LibreSign\XObjectTemplate\PlainTextCompiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class PlainTextCompilerScenarioTest extends TestCase
{
    #[DataProvider('literalTextProvider')]
    public function testRoundTripsLiteralText(string $text, string $expected, float $width): void
    {
        $builder = (new TemplateDocumentBuilder())->withUnicodeFontFiles([
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        ]);
        $result = (new PlainTextCompiler($builder))->compile(
            [new PlainTextBlock($text, 2, 2, $width - 4, 96)],
            $width,
            100,
        );
        $pdf = (new SinglePagePdfExporter())->export($result);
        $process = new Process(['pdftotext', '-raw', '-nopgbrk', '-enc', 'UTF-8', '-', '-'], input: $pdf);
        $process->mustRun();

        self::assertSame($expected, trim($process->getOutput()));
    }

    public static function literalTextProvider(): iterable
    {
        yield 'literal markup and placeholders' => [
            '<name> & {{ signer }} (A) \\ B', '<name> & {{ signer }} (A) \\ B', 300.0,
        ];
        yield 'French decomposed and line endings' => [
            "Signe\u{0301} par Rene\u{0301}e\r\nE\u{0301}metteur\rExample",
            "Signé par Renée\nÉmetteur\nExample",
            300.0,
        ];
        yield 'Portuguese' => ['João Gonçalves', 'João Gonçalves', 300.0];
        yield 'Cyrillic' => ['Иван Иванов', 'Иван Иванов', 300.0];
        yield 'wide ASCII word without hyphens' => ['WWWWWW', "WWWW\nWW", 44.0];
        yield 'wide Unicode word without hyphens' => ['WÉWÉWÉWÉ', "WÉWÉ\nWÉWÉ", 44.0];
        yield 'tab' => ["Signed\tby Renée", 'Signed by Renée', 300.0];
        yield 'NBSP' => ["Renée\u{00a0}Dupont", 'Renée Dupont', 300.0];
    }
}
