<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf;

use LibreSign\XObjectTemplate\Dto\CompileRequest;
use LibreSign\XObjectTemplate\Layout\LayoutImage;
use LibreSign\XObjectTemplate\Layout\LayoutLine;
use LibreSign\XObjectTemplate\Layout\LayoutResult;
use LibreSign\XObjectTemplate\Pdf\TemplateDocumentBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TemplateDocumentBuilderTest extends TestCase
{
    public function testBuildCreatesDocumentPayloadParts(): void
    {
        $builder = new TemplateDocumentBuilder();
        $layout = new LayoutResult(
            lines: [
                new LayoutLine(
                    text: 'Rendered for Alice',
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
                    source: '/tmp/example-image.png',
                ),
            ],
        );

        $result = $builder->build(new CompileRequest(html: '<p>unused</p>'), $layout, 1_000_000_000, 2);

        self::assertStringStartsWith("q\nq ", $result->contentStream);
        self::assertStringContainsString('/F1 10.000000 Tf', $result->contentStream);
        self::assertStringContainsString('/Im0 Do', $result->contentStream);
        self::assertStringContainsString("\nET\nQ", $result->contentStream);
        self::assertSame([0.0, 0.0, 240.0, 84.0], $result->bbox);
        self::assertSame('Rendered for Alice', $layout->lines[0]->text);
        self::assertArrayHasKey('Font', $result->resources);
        self::assertArrayHasKey('XObject', $result->resources);
        self::assertSame('/Helvetica', $result->resources['Font']['F1']['BaseFont']);
        self::assertSame('/XObject', $result->resources['XObject']['Im0']['Type']);
        self::assertSame('/Image', $result->resources['XObject']['Im0']['Subtype']);
        self::assertSame(24.0, $result->resources['XObject']['Im0']['Width']);
        self::assertSame(24.0, $result->resources['XObject']['Im0']['Height']);
        self::assertSame('/tmp/example-image.png', $result->resources['XObject']['Im0']['Source']);
        self::assertSame(1, $result->metadata['line_count']);
        self::assertSame(1, $result->metadata['image_count']);
        self::assertSame(2, $result->metadata['node_count']);
        self::assertArrayHasKey('render_ms', $result->metadata);
    }

    public function testBuildMetadataDefaultsNodeCountAndUsesRoundedMilliseconds(): void
    {
        $builder = new TemplateDocumentBuilder(clock: static fn (): int => 2_000_000);
        $layout = new LayoutResult(lines: [], images: []);

        $metadata = $builder->buildMetadata($layout, 0);

        self::assertSame(0, $metadata['line_count']);
        self::assertSame(0, $metadata['image_count']);
        self::assertSame(0, $metadata['node_count']);
        self::assertSame(2.0, $metadata['render_ms']);
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

    public function testBuildContentStreamIsDirectlyUsableForImagesAndEscapedText(): void
    {
        $builder = new TemplateDocumentBuilder();
        $stream = $builder->buildContentStream(new LayoutResult(
            lines: [
                new LayoutLine(
                    text: 'Marker (QA)',
                    x: 12.0,
                    y: 22.0,
                    fontSize: 9.0,
                    fontAlias: 'F2',
                    rgbColor: '#abcdef',
                ),
            ],
            images: [
                new LayoutImage(alias: 'Im7', x: 1.0, y: 2.0, width: 3.0, height: 4.0, source: '/img.png'),
            ],
        ));

        self::assertStringContainsString('q 3.000000 0 0 4.000000 1.000000 2.000000 cm /Im7 Do Q', $stream);
        self::assertStringContainsString('/F2 9.000000 Tf', $stream);
        self::assertStringContainsString('0.6706 0.8039 0.9373 rg', $stream);
        self::assertStringContainsString('1 0 0 1 12.000000 22.000000 Tm', $stream);
        self::assertStringContainsString('(Marker \\(QA\\)) Tj', $stream);
    }

    public function testBuildContentStreamUsesAbsoluteTextMatrixForMultipleLines(): void
    {
        $builder = new TemplateDocumentBuilder();

        $stream = $builder->buildContentStream(new LayoutResult(
            lines: [
                new LayoutLine(
                    text: 'First line',
                    x: 18.0,
                    y: 72.0,
                    fontSize: 10.0,
                    fontAlias: 'F1',
                    rgbColor: '#000000',
                ),
                new LayoutLine(
                    text: 'Second line',
                    x: 120.0,
                    y: 40.0,
                    fontSize: 10.0,
                    fontAlias: 'F1',
                    rgbColor: '#000000',
                ),
            ],
            images: [],
        ));

        self::assertStringContainsString('1 0 0 1 18.000000 72.000000 Tm', $stream);
        self::assertStringContainsString('1 0 0 1 120.000000 40.000000 Tm', $stream);
        self::assertStringNotContainsString(' Td', $stream);
    }

    public function testBuildResourcesExposesImageDictionaryAndCustomFontsFromDerivedBuilder(): void
    {
        $builder = (new TemplateDocumentBuilder())->withFontResources([
            'Z9' => [
                'Type' => '/Font',
                'Subtype' => '/Type1',
                'BaseFont' => '/Courier',
            ],
        ]);

        $resources = $builder->buildResources(new LayoutResult(
            lines: [],
            images: [
                new LayoutImage(alias: 'Im9', x: 0.0, y: 0.0, width: 10.0, height: 11.0, source: '/proof.png'),
            ],
        ));

        self::assertArrayHasKey('Z9', $resources['Font']);
        self::assertArrayNotHasKey('F1', $resources['Font']);
        self::assertSame('/proof.png', $resources['XObject']['Im9']['Source']);
        self::assertSame(10.0, $resources['XObject']['Im9']['Width']);
        self::assertSame(11.0, $resources['XObject']['Im9']['Height']);
    }

    #[DataProvider('metadataRoundingProvider')]
    public function testBuildMetadataUsesDeterministicClockRounding(
        int $finishedAtNs,
        int $startedAtNs,
        float $expectedRenderMs,
    ): void {
        $builder = new TemplateDocumentBuilder(clock: static fn (): int => $finishedAtNs);

        $metadata = $builder->buildMetadata(new LayoutResult(lines: [], images: []), $startedAtNs);

        self::assertSame($expectedRenderMs, $metadata['render_ms']);
    }

    /**
     * @return iterable<string, array{finishedAtNs: int, startedAtNs: int, expectedRenderMs: float}>
     */
    public static function metadataRoundingProvider(): iterable
    {
        yield 'three-decimal rounding stays exact' => [
            'finishedAtNs' => 123_456_789,
            'startedAtNs' => 0,
            'expectedRenderMs' => 123.457,
        ];

        yield 'denominator stays at one million nanoseconds' => [
            'finishedAtNs' => 500_499_001,
            'startedAtNs' => 0,
            'expectedRenderMs' => 500.499,
        ];

        yield 'elapsed time subtracts start and keeps third-decimal rounding' => [
            'finishedAtNs' => 510_499_500,
            'startedAtNs' => 10_000_000,
            'expectedRenderMs' => 500.5,
        ];
    }
}
