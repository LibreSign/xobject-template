<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit;

use LibreSign\XObjectTemplate\Dto\CompileRequest;
use LibreSign\XObjectTemplate\Layout\LayoutImage;
use LibreSign\XObjectTemplate\Layout\LayoutLine;
use LibreSign\XObjectTemplate\Layout\LayoutResult;
use LibreSign\XObjectTemplate\Pdf\TemplateDocumentBuilder;
use PHPUnit\Framework\TestCase;

final class TemplateDocumentBuilderTest extends TestCase
{
    public function testBuildCreatesDocumentPayloadParts(): void
    {
        $builder = new TemplateDocumentBuilder();
        $layout = new LayoutResult(
            lines: [
                new LayoutLine(
                    text: 'Signed by Alice',
                    x: 12.0,
                    y: 48.0,
                    fontSize: 10.0,
                    fontAlias: 'F1',
                    rgbColor: '#000000',
                ),
            ],
            images: [
                new LayoutImage(
                    alias: 'Im0',
                    x: 4.0,
                    y: 4.0,
                    width: 24.0,
                    height: 24.0,
                    source: '/tmp/signature.png',
                ),
            ],
        );

        $result = $builder->build(new CompileRequest(html: '<p>unused</p>'), $layout, 1_000_000_000, 2);

        self::assertStringStartsWith("q\nq ", $result->contentStream);
        self::assertStringContainsString('/F1 10.000000 Tf', $result->contentStream);
        self::assertStringContainsString('/Im0 Do', $result->contentStream);
        self::assertStringContainsString("\nET\nQ", $result->contentStream);
        self::assertSame([0.0, 0.0, 240.0, 84.0], $result->bbox);
        self::assertSame('Signed by Alice', $layout->lines[0]->text);
        self::assertArrayHasKey('Font', $result->resources);
        self::assertArrayHasKey('XObject', $result->resources);
        self::assertSame('/Helvetica', $result->resources['Font']['F1']['BaseFont']);
        self::assertSame('/XObject', $result->resources['XObject']['Im0']['Type']);
        self::assertSame('/Image', $result->resources['XObject']['Im0']['Subtype']);
        self::assertSame(24.0, $result->resources['XObject']['Im0']['Width']);
        self::assertSame(24.0, $result->resources['XObject']['Im0']['Height']);
        self::assertSame('/tmp/signature.png', $result->resources['XObject']['Im0']['Source']);
        self::assertSame(1, $result->metadata['line_count']);
        self::assertSame(1, $result->metadata['image_count']);
        self::assertSame(2, $result->metadata['node_count']);
        self::assertArrayHasKey('render_ms', $result->metadata);
    }

    public function testBuildMetadataDefaultsNodeCountAndUsesRoundedMilliseconds(): void
    {
        $builder = new TemplateDocumentBuilder();
        $layout = new LayoutResult(lines: [], images: []);
        $startedAtNs = hrtime(true) - 2_000_000;

        $metadata = $builder->buildMetadata($layout, $startedAtNs);

        self::assertSame(0, $metadata['line_count']);
        self::assertSame(0, $metadata['image_count']);
        self::assertSame(0, $metadata['node_count']);
        self::assertGreaterThan(0.5, $metadata['render_ms']);
        self::assertLessThan(1000.0, $metadata['render_ms']);
    }
}
