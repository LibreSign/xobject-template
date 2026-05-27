<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit;

use LibreSign\XObjectTemplate\Dto\CompileRequest;
use LibreSign\XObjectTemplate\Layout\LayoutResult;
use LibreSign\XObjectTemplate\Pdf\ColorParser;
use LibreSign\XObjectTemplate\Pdf\PdfEscaper;
use LibreSign\XObjectTemplate\Pdf\TemplateDocumentBuilder;
use LibreSign\XObjectTemplate\XObjectTemplateCompiler;
use PHPUnit\Framework\TestCase;

final class TemplateDocumentBuilderCompatibilityTest extends TestCase
{
    public function testCompilerConstructorStillAcceptsLegacyPdfDependencies(): void
    {
        $compiler = new XObjectTemplateCompiler(
            null,
            null,
            new PdfEscaper(),
            new ColorParser(),
        );

        $result = $compiler->compile(new CompileRequest(html: '<p>Hello</p>'));

        self::assertStringContainsString('(Hello) Tj', $result->contentStream);
        self::assertSame([0.0, 0.0, 240.0, 84.0], $result->bbox);
        self::assertArrayHasKey('Font', $result->resources);
    }

    public function testBuilderBuildsPayloadWithCustomMetadataCount(): void
    {
        $builder = new TemplateDocumentBuilder();
        $result = $builder->build(
            new CompileRequest(html: '<p>Hello</p>', width: 100.0, height: 50.0),
            new LayoutResult(lines: [], images: []),
            1_000_000_000,
            7,
        );

        self::assertSame([0.0, 0.0, 100.0, 50.0], $result->bbox);
        self::assertSame(7, $result->metadata['node_count']);
        self::assertSame(0, $result->metadata['line_count']);
        self::assertSame(0, $result->metadata['image_count']);
    }

    public function testBuilderBuildUsesDefaultNodeCountWhenNotProvided(): void
    {
        $builder = new TemplateDocumentBuilder();

        $result = $builder->build(
            new CompileRequest(html: '<p>Hello</p>', width: 100.0, height: 50.0),
            new LayoutResult(lines: [], images: []),
            hrtime(true),
        );

        self::assertSame(0, $result->metadata['node_count']);
    }
}
