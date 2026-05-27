<?php

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

        self::assertStringContainsString('/F1 10.000000 Tf', $result->contentStream);
        self::assertStringContainsString('/Im0 Do', $result->contentStream);
        self::assertSame([0.0, 0.0, 240.0, 84.0], $result->bbox);
        self::assertSame('Signed by Alice', $layout->lines[0]->text);
        self::assertArrayHasKey('Font', $result->resources);
        self::assertArrayHasKey('XObject', $result->resources);
        self::assertSame('/Helvetica', $result->resources['Font']['F1']['BaseFont']);
        self::assertSame('/tmp/signature.png', $result->resources['XObject']['Im0']['Source']);
        self::assertSame(1, $result->metadata['line_count']);
        self::assertSame(1, $result->metadata['image_count']);
        self::assertSame(2, $result->metadata['node_count']);
        self::assertArrayHasKey('render_ms', $result->metadata);
    }
}