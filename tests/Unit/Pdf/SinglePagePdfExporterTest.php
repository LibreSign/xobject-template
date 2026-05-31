<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf;

use LibreSign\XObjectTemplate\Dto\CompileResult;
use LibreSign\XObjectTemplate\Pdf\CompileResultResourceExtractor;
use LibreSign\XObjectTemplate\Pdf\EmbeddedPdfImage;
use LibreSign\XObjectTemplate\Pdf\PdfImageEmbedderInterface;
use LibreSign\XObjectTemplate\Pdf\SinglePagePdfExporter;
use LibreSign\XObjectTemplate\Tests\Support\Pdf\RecordingPdfImageEmbedder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class SinglePagePdfExporterTest extends TestCase
{
    public function testExportWrapsCompileResultInSinglePagePdfSizedFromBoundingBox(): void
    {
        $exporter = new SinglePagePdfExporter(new class () implements PdfImageEmbedderInterface
        {
            public function embed(string $source): EmbeddedPdfImage
            {
                throw new \LogicException(sprintf('Image embedding should not be called for %s.', $source));
            }
        });

        $pdf = $exporter->export(new CompileResult(
            contentStream: "q\nBT\n/F1 10 Tf\n0 0 0 rg\n8 72 Td\n(Rendered for Alice) Tj\nET\nQ",
            resources: [
                'Font' => [
                    'F1' => [
                        'Type' => '/Font',
                        'Subtype' => '/Type1',
                        'BaseFont' => '/Helvetica',
                    ],
                ],
            ],
            bbox: [12.5, 4.0, 252.5, 88.0],
        ));

        self::assertStringStartsWith('%PDF-1.4', $pdf);
        self::assertStringContainsString('/Type /Catalog', $pdf);
        self::assertStringContainsString('/Type /Page', $pdf);
        self::assertStringContainsString('/MediaBox [0 0 240 84]', $pdf);
        self::assertStringContainsString('/Subtype /Form', $pdf);
        self::assertStringContainsString('/BBox [12.5 4 252.5 88]', $pdf);
        self::assertStringContainsString('q 1 0 0 1 -12.5 -4 cm /Fm0 Do Q', $pdf);
        self::assertStringContainsString('/BaseFont /Helvetica', $pdf);
        self::assertStringContainsString('(Rendered for Alice) Tj', $pdf);
    }

    public function testExportUsesInjectedImageEmbedderForImageResources(): void
    {
        $embedder = new RecordingPdfImageEmbedder(
            dictionary: [
                'Type' => '/XObject',
                'Subtype' => '/Image',
                'Width' => 1,
                'Height' => 1,
                'ColorSpace' => '/DeviceRGB',
                'BitsPerComponent' => 8,
                'Filter' => '/FlateDecode',
                'DecodeParms' => [
                    'Predictor' => 15,
                    'Colors' => 3,
                    'BitsPerComponent' => 8,
                    'Columns' => 1,
                ],
            ],
            stream: gzcompress("\x00\xff\x00\x00"),
        );

        $exporter = new SinglePagePdfExporter($embedder);

        $pdf = $exporter->export(new CompileResult(
            contentStream: 'q 18 0 0 18 0 45 cm /Im0 Do Q',
            resources: [
                'Font' => [],
                'XObject' => [
                    'Im0' => [
                        'Type' => '/XObject',
                        'Subtype' => '/Image',
                        'Source' => '/tmp/example-image.png',
                        'Width' => 18.0,
                        'Height' => 18.0,
                    ],
                ],
            ],
            bbox: [0.0, 0.0, 240.0, 84.0],
        ));

        self::assertSame(['/tmp/example-image.png'], $embedder->sources);
        self::assertStringContainsString('/Subtype /Image', $pdf);
        self::assertStringContainsString('/ColorSpace /DeviceRGB', $pdf);
        self::assertStringContainsString(
            '/DecodeParms << /Predictor 15 /Colors 3 /BitsPerComponent 8 /Columns 1 >>',
            $pdf,
        );
        self::assertStringContainsString('/Im0', $pdf);
        self::assertStringContainsString('/Im0 Do', $pdf);
    }

    public function testExportSerializesSoftMasksLiteralStringsAndBooleans(): void
    {
        $exporter = new SinglePagePdfExporter(new class () implements PdfImageEmbedderInterface
        {
            public function embed(string $source): EmbeddedPdfImage
            {
                return new EmbeddedPdfImage(
                    dictionary: [
                        'Type' => '/XObject',
                        'Subtype' => '/Image',
                        'Width' => 1,
                        'Height' => 1,
                        'ColorSpace' => '/DeviceRGB',
                        'BitsPerComponent' => 8,
                        'Filter' => '/FlateDecode',
                        'Note' => 'Preview (QA)',
                        'Interpolate' => true,
                    ],
                    stream: gzcompress("\x00\xff\x00\x00"),
                    softMask: new EmbeddedPdfImage(
                        dictionary: [
                            'Type' => '/XObject',
                            'Subtype' => '/Image',
                            'Width' => 1,
                            'Height' => 1,
                            'ColorSpace' => '/DeviceGray',
                            'BitsPerComponent' => 8,
                            'Filter' => '/FlateDecode',
                        ],
                        stream: gzcompress("\x00\x80"),
                    ),
                );
            }
        });

        $pdf = $exporter->export(new CompileResult(
            contentStream: 'q 10 0 0 10 0 0 cm /Im0 Do Q',
            resources: [
                'Font' => [],
                'XObject' => [
                    'Im0' => [
                        'Type' => '/XObject',
                        'Subtype' => '/Image',
                        'Source' => '/tmp/mask-preview.png',
                        'Width' => 10.0,
                        'Height' => 10.0,
                    ],
                ],
            ],
            bbox: [0.0, 0.0, 40.0, 40.0],
        ));

        self::assertStringContainsString('/SMask', $pdf);
        self::assertStringContainsString('/Note (Preview \(QA\))', $pdf);
        self::assertStringContainsString('/Interpolate true', $pdf);
    }

    public function testExportPreservesAllFontAndImageReferencesInPageTreeAndTrailer(): void
    {
        $embedder = new RecordingPdfImageEmbedder(
            dictionary: [
                'Type' => '/XObject',
                'Subtype' => '/Image',
                'Width' => 1,
                'Height' => 1,
                'ColorSpace' => '/DeviceRGB',
                'BitsPerComponent' => 8,
                'Filter' => '/FlateDecode',
            ],
            stream: 'RGB',
        );

        $exporter = new SinglePagePdfExporter($embedder);

        $pdf = $exporter->export(new CompileResult(
            contentStream: 'BT ET',
            resources: [
                'Font' => [
                    'F1' => [
                        'Type' => '/Font',
                        'Subtype' => '/Type1',
                        'BaseFont' => '/Helvetica',
                    ],
                    'F2' => [
                        'Type' => '/Font',
                        'Subtype' => '/Type1',
                        'BaseFont' => '/Times-Roman',
                    ],
                ],
                'XObject' => [
                    'Im0' => [
                        'Type' => '/XObject',
                        'Subtype' => '/Image',
                        'Source' => '/tmp/left.png',
                        'Width' => 10.0,
                        'Height' => 10.0,
                    ],
                    'Im1' => [
                        'Type' => '/XObject',
                        'Subtype' => '/Image',
                        'Source' => '/tmp/right.png',
                        'Width' => 10.0,
                        'Height' => 10.0,
                    ],
                ],
            ],
            bbox: [0.0, 0.0, 40.0, 20.0],
        ));

        $expectedCatalogObject = implode("\n", [
            '1 0 obj',
            '<< /Type /Catalog /Pages 2 0 R >>',
            'endobj',
        ]);
        $expectedPagesObject = implode("\n", [
            '2 0 obj',
            '<< /Type /Pages /Count 1 /Kids [3 0 R] >>',
            'endobj',
        ]);
        $expectedPageObject = implode("\n", [
            '3 0 obj',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 40 20] '
                . '/Resources << /XObject << /Fm0 5 0 R >> >> /Contents 4 0 R >>',
            'endobj',
        ]);
        $expectedFormResources = '/Resources << /Font << /F1 6 0 R /F2 7 0 R >> '
            . '/XObject << /Im0 8 0 R /Im1 9 0 R >> >>';

        self::assertSame(['/tmp/left.png', '/tmp/right.png'], $embedder->sources);
        self::assertStringContainsString($expectedCatalogObject, $pdf);
        self::assertStringContainsString($expectedPagesObject, $pdf);
        self::assertStringContainsString($expectedPageObject, $pdf);
        self::assertStringContainsString('/Type /XObject /Subtype /Form /FormType 1', $pdf);
        self::assertStringContainsString($expectedFormResources, $pdf);
        self::assertStringContainsString("xref\n0 10\n", $pdf);
        self::assertStringContainsString("trailer\n<< /Size 10 /Root 1 0 R >>", $pdf);
    }

    public function testExportWrapsPageAndFormStreamsInExpectedOrder(): void
    {
        $exporter = new SinglePagePdfExporter(new class () implements PdfImageEmbedderInterface
        {
            public function embed(string $source): EmbeddedPdfImage
            {
                throw new \LogicException(sprintf('Image embedding should not be called for %s.', $source));
            }
        });

        $contentStream = "BT\n/F1 10 Tf\n0 0 0 rg\n8 12 Td\n(Hello) Tj\nET";
        $pageStream = 'q 1 0 0 1 0 0 cm /Fm0 Do Q';

        $pdf = $exporter->export(new CompileResult(
            contentStream: $contentStream,
            resources: [
                'Font' => [
                    'F1' => [
                        'Type' => '/Font',
                        'Subtype' => '/Type1',
                        'BaseFont' => '/Helvetica',
                    ],
                ],
            ],
            bbox: [0.0, 0.0, 40.0, 20.0],
        ));

        $expectedPageContentObject = implode("\n", [
            '4 0 obj',
            sprintf('<< /Length %d >>', strlen($pageStream)),
            'stream',
            $pageStream,
            'endstream',
            'endobj',
        ]);
        $expectedFormStreamFragment = implode("\n", [
            sprintf('/Length %d >>', strlen($contentStream)),
            'stream',
            $contentStream,
            'endstream',
        ]);

        self::assertStringContainsString($expectedPageContentObject, $pdf);
        self::assertStringContainsString($expectedFormStreamFragment, $pdf);
    }

    public function testExportUsesInjectedResourceExtractorWhenProvided(): void
    {
        $calls = [];
        $resourceExtractor = new class ($calls) extends CompileResultResourceExtractor
        {
            /** @var list<string> */
            public array $calls;

            /**
             * @param list<string> $calls
             */
            public function __construct(array &$calls)
            {
                $this->calls = &$calls;
            }

            public function extract(CompileResult $result, string $resourceType, string $itemMessageTemplate): array
            {
                $this->calls[] = $resourceType;

                if ($resourceType === 'Font') {
                    return [
                        'Z9' => [
                            'Type' => '/Font',
                            'Subtype' => '/Type1',
                            'BaseFont' => '/Courier',
                        ],
                    ];
                }

                return [];
            }
        };

        $exporter = new SinglePagePdfExporter(
            new class () implements PdfImageEmbedderInterface
            {
                public function embed(string $source): EmbeddedPdfImage
                {
                    throw new \LogicException(sprintf('Image embedding should not be called for %s.', $source));
                }
            },
            $resourceExtractor,
        );

        $pdf = $exporter->export(new CompileResult(
            contentStream: 'BT ET',
            resources: ['Font' => []],
            bbox: [0.0, 0.0, 40.0, 20.0],
        ));

        self::assertSame(['Font', 'XObject'], $calls);
        self::assertStringContainsString('/BaseFont /Courier', $pdf);
    }

    public function testSerializeValueFormatsNumbersListsAndRawPdfValues(): void
    {
        $exporter = new SinglePagePdfExporter();

        self::assertSame('3', $this->invokeExporterMethod($exporter, 'serializeValue', 3));
        self::assertSame('12.5', $this->invokeExporterMethod($exporter, 'serializeValue', 12.5));
        self::assertSame(
            '[/Name 2 0 R (text) true 3]',
            $this->invokeExporterMethod($exporter, 'serializeValue', ['/Name', '2 0 R', 'text', true, 3]),
        );
    }

    public function testRenderDocumentSortsObjectsAndRejectsReservedGaps(): void
    {
        $exporter = new SinglePagePdfExporter();
        $rendered = $this->invokeExporterMethod($exporter, 'renderDocument', [
            2 => '<< /Type /Pages /Count 0 >>',
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
        ], 1);

        self::assertStringContainsString(
            "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj",
            (string) $rendered,
        );
        self::assertStringContainsString("xref\n0 3\n", $rendered);
        self::assertStringContainsString(
            sprintf(
                "0000000000 65535 f \n%010d 00000 n \n%010d 00000 n \n",
                strpos((string) $rendered, "1 0 obj\n"),
                strpos((string) $rendered, "2 0 obj\n"),
            ),
            (string) $rendered,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PDF object 2 was reserved but not written.');

        $this->invokeExporterMethod($exporter, 'renderDocument', [1 => '<< /Type /Catalog >>', 2 => null], 1);
    }

    public function testSerializeValueRejectsAssociativeArraysWithNonStringKeys(): void
    {
        $exporter = new SinglePagePdfExporter();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PDF dictionaries must use string keys.');

        $this->invokeExporterMethod($exporter, 'serializeValue', [0 => 'alpha', 2 => 'beta']);
    }

    public function testRequireStringKeyedArrayRejectsNonArrayValues(): void
    {
        $exporter = new SinglePagePdfExporter();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PDF dictionaries must use string keys.');

        $this->invokeExporterMethod(
            $exporter,
            'requireStringKeyedArray',
            'not-an-array',
            'PDF dictionaries must use string keys.',
        );
    }

    #[DataProvider('rawPdfValueProvider')]
    public function testIsRawPdfValueMatchesOnlyPdfNamesAndReferences(string $value, bool $expected): void
    {
        $exporter = new SinglePagePdfExporter();

        self::assertSame($expected, $this->invokeExporterMethod($exporter, 'isRawPdfValue', $value));
    }

    #[DataProvider('invalidCompileResultProvider')]
    public function testExportRejectsInvalidCompileResults(CompileResult $result, string $expectedMessage): void
    {
        $exporter = new SinglePagePdfExporter(new class () implements PdfImageEmbedderInterface
        {
            public function embed(string $source): EmbeddedPdfImage
            {
                return new EmbeddedPdfImage(
                    dictionary: [
                        'Type' => '/XObject',
                        'Subtype' => '/Image',
                        'Width' => 1,
                        'Height' => 1,
                        'ColorSpace' => '/DeviceRGB',
                        'BitsPerComponent' => 8,
                        'Filter' => '/FlateDecode',
                    ],
                    stream: gzcompress("\x00\xff\x00\x00"),
                );
            }
        });

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        $exporter->export($result);
    }

    /**
     * @return iterable<string, array{result: CompileResult, expectedMessage: string}>
     */
    public static function invalidCompileResultProvider(): iterable
    {
        yield 'bbox without positive area' => [
            'result' => new CompileResult(
                contentStream: 'BT ET',
                resources: ['Font' => []],
                bbox: [0.0, 0.0, 0.0, 40.0],
            ),
            'expectedMessage' => 'CompileResult bbox must describe a positive area.',
        ];

        yield 'bbox without positive height' => [
            'result' => new CompileResult(
                contentStream: 'BT ET',
                resources: ['Font' => []],
                bbox: [0.0, 0.0, 40.0, 0.0],
            ),
            'expectedMessage' => 'CompileResult bbox must describe a positive area.',
        ];

        yield 'font resource must be an array' => [
            'result' => new CompileResult(
                contentStream: 'BT ET',
                resources: ['Font' => ['F1' => '/Helvetica']],
                bbox: [0.0, 0.0, 40.0, 40.0],
            ),
            'expectedMessage' => 'Font resource "F1" must be an array.',
        ];

        yield 'font collection must be an array' => [
            'result' => new CompileResult(
                contentStream: 'BT ET',
                resources: ['Font' => '/Helvetica'],
                bbox: [0.0, 0.0, 40.0, 40.0],
            ),
            'expectedMessage' => 'Font resources must be an array.',
        ];

        yield 'unsupported dictionary value type' => [
            'result' => new CompileResult(
                contentStream: 'BT ET',
                resources: [
                    'Font' => [
                        'F1' => [
                            'Type' => '/Font',
                            'Subtype' => '/Type1',
                            'Meta' => new \stdClass(),
                        ],
                    ],
                ],
                bbox: [0.0, 0.0, 40.0, 40.0],
            ),
            'expectedMessage' => 'Unsupported PDF value type "stdClass".',
        ];

        yield 'xobject resource must be an array' => [
            'result' => new CompileResult(
                contentStream: 'BT ET',
                resources: [
                    'Font' => [],
                    'XObject' => ['Im0' => '/Image'],
                ],
                bbox: [0.0, 0.0, 40.0, 40.0],
            ),
            'expectedMessage' => 'XObject resource "Im0" must be an array.',
        ];

        yield 'xobject collection must be an array' => [
            'result' => new CompileResult(
                contentStream: 'BT ET',
                resources: [
                    'Font' => [],
                    'XObject' => '/Image',
                ],
                bbox: [0.0, 0.0, 40.0, 40.0],
            ),
            'expectedMessage' => 'XObject resources must be an array.',
        ];

        yield 'missing image source' => [
            'result' => new CompileResult(
                contentStream: 'BT ET',
                resources: [
                    'Font' => [],
                    'XObject' => [
                        'Im0' => [
                            'Type' => '/XObject',
                            'Subtype' => '/Image',
                            'Source' => '',
                        ],
                    ],
                ],
                bbox: [0.0, 0.0, 40.0, 40.0],
            ),
            'expectedMessage' => 'XObject resource "Im0" must expose a non-empty Source.',
        ];
    }

    /**
     * @return iterable<string, array{value: string, expected: bool}>
     */
    public static function rawPdfValueProvider(): iterable
    {
        yield 'name' => ['value' => '/Helvetica', 'expected' => true];
        yield 'reference' => ['value' => '12 0 R', 'expected' => true];
        yield 'missing start anchor' => ['value' => 'prefix 12 0 R', 'expected' => false];
        yield 'missing end anchor' => ['value' => '12 0 R suffix', 'expected' => false];
        yield 'literal string' => ['value' => 'hello', 'expected' => false];
    }

    private function invokeExporterMethod(SinglePagePdfExporter $exporter, string $method, mixed ...$arguments): mixed
    {
        $reflectionMethod = new ReflectionMethod($exporter, $method);

        return $reflectionMethod->invoke($exporter, ...$arguments);
    }
}
