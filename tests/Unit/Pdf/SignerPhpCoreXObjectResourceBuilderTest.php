<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Pdf;

use InvalidArgumentException;
use LibreSign\XObjectTemplate\Pdf\EmbeddedPdfImage;
use LibreSign\XObjectTemplate\Pdf\PdfImageEmbedderInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use LibreSign\XObjectTemplate\Pdf\SignerPhpCoreXObjectResourceBuilder;
use LibreSign\XObjectTemplate\Pdf\TrueTypeFont;
use PHPUnit\Framework\TestCase;
use LibreSign\XObjectTemplate\Tests\Support\PngFixtureFactory;
use SignerPHP\PdfCore\PdfDocument;
use SignerPHP\PdfCore\PdfValue\PDFValueReference;

final class SignerPhpCoreXObjectResourceBuilderTest extends TestCase
{
    public function testCreatesIndirectFontResourcesInExistingPdfDocument(): void
    {
        $this->withFont(function (TrueTypeFont $font): void {
            $document = new PdfDocument();
            $document->createObject(['Type' => '/Catalog']);
            $resources = [
                'Font' => [
                    'F1' => [
                        'Type' => '/Font',
                        'Subtype' => '/Type1',
                        'BaseFont' => '/Helvetica',
                    ],
                    'U1' => [
                        '__TrueTypeFont' => $font,
                        '__Mappings' => [
                            ['glyph' => 0x41, 'unicode' => [0x41]],
                            ['glyph' => 0x41, 'unicode' => [0x1F600]],
                        ],
                    ],
                ],
            ];

            $result = (new SignerPhpCoreXObjectResourceBuilder())->materialize($document, $resources);

            self::assertInstanceOf(PDFValueReference::class, $result['Font']['F1']);
            self::assertStringContainsString('/BaseFont/Helvetica', $document->getPdfObjects()[2]->toPdfEntry());
            self::assertInstanceOf(PDFValueReference::class, $result['Font']['U1']);
            self::assertSame('8 0 R', (string) $result['Font']['U1']);
            self::assertCount(8, $document->getPdfObjects());
            self::assertSame(8, $document->getMaxOid());
            self::assertArrayHasKey('__TrueTypeFont', $resources['Font']['U1']);

            $fontFile = $document->getPdfObjects()[3];
            self::assertSame($font->fontData(), $fontFile->getStream(false));
            self::assertStringContainsString('/Length1 ', $fontFile->toPdfEntry());

            $cidMap = $document->getPdfObjects()[4];
            self::assertSame(pack('nnn', 0, 3, 3), $cidMap->getStream(false));

            $unicode = $document->getPdfObjects()[5];
            self::assertStringContainsString('<0001> <0041>', $unicode->getStream(false));
            self::assertStringContainsString('<0002> <D83DDE00>', $unicode->getStream(false));

            $descriptor = $document->getPdfObjects()[6]->toPdfEntry();
            self::assertStringContainsString('/FontFile2 3 0 R', $descriptor);

            $cidFont = $document->getPdfObjects()[7]->toPdfEntry();
            self::assertStringContainsString('/CIDToGIDMap 4 0 R', $cidFont);
            self::assertStringContainsString('/FontDescriptor 6 0 R', $cidFont);
            self::assertStringContainsString('/Registry(Adobe)', $cidFont);
            self::assertStringContainsString('/Ordering(Identity)', $cidFont);

            $type0 = $document->getPdfObjects()[8]->toPdfEntry();
            self::assertStringContainsString('/DescendantFonts[7 0 R]', $type0);
            self::assertStringContainsString('/ToUnicode 5 0 R', $type0);
        });
    }

    public function testEmptyResourceDictionariesAreOmittedWithoutAllocatingObjects(): void
    {
        $document = new PdfDocument();
        $resources = (new SignerPhpCoreXObjectResourceBuilder())->materialize($document, [
            'Font' => [],
            'XObject' => [],
            'ProcSet' => ['/PDF'],
        ]);

        self::assertSame(['ProcSet' => ['/PDF']], $resources);
        self::assertSame([], $document->getPdfObjects());
    }

    public function testPreservesExistingFontReferencesAndOnlyMaterializesGeneratedStandardFonts(): void
    {
        $document = new PdfDocument();
        $existing = $document->createObject(['Type' => '/Font']);
        $reference = new PDFValueReference($existing->getOid());
        $fonts = [
            'Existing' => $reference,
            'TypeOnly' => ['Type' => '/Font'],
            'SubtypeOnly' => ['Subtype' => '/Type1'],
            'Standard' => ['Type' => '/Font', 'Subtype' => '/Type1', 'BaseFont' => '/Helvetica'],
        ];
        $result = (new SignerPhpCoreXObjectResourceBuilder())->materialize($document, ['Font' => $fonts]);
        self::assertSame($reference, $result['Font']['Existing']);
        self::assertSame($fonts['TypeOnly'], $result['Font']['TypeOnly']);
        self::assertSame($fonts['SubtypeOnly'], $result['Font']['SubtypeOnly']);
        self::assertInstanceOf(PDFValueReference::class, $result['Font']['Standard']);
        self::assertCount(2, $document->getPdfObjects());
    }

    public function testRejectsSourceBasedImageResourcesWithoutCreatingPdfObjects(): void
    {
        $document = new PdfDocument();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an existing file');
        try {
            (new SignerPhpCoreXObjectResourceBuilder())->materialize($document, [
                'XObject' => ['Im1' => ['Source' => '/tmp/image.png']],
            ]);
        } finally {
            self::assertSame([], $document->getPdfObjects());
        }
    }

    public function testEmbedsTransparentPngWithIndirectSoftMask(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'core-resource-png-');
        self::assertNotFalse($path);
        try {
            $png = PngFixtureFactory::createRgbaPngFromPixelRenderer(
                1,
                1,
                static fn (): array => [255, 0, 0, 128],
            );
            self::assertSame(strlen($png), file_put_contents($path, $png));

            $document = new PdfDocument();
            $result = (new SignerPhpCoreXObjectResourceBuilder())->materialize($document, [
                'XObject' => ['Im1' => ['Source' => $path]],
            ]);

            self::assertInstanceOf(PDFValueReference::class, $result['XObject']['Im1']);
            self::assertSame('2 0 R', (string) $result['XObject']['Im1']);
            self::assertCount(2, $document->getPdfObjects());
            $softMask = $document->getPdfObjects()[1]->toPdfEntry();
            $image = $document->getPdfObjects()[2]->toPdfEntry();
            self::assertStringContainsString('/DeviceGray', $softMask);
            self::assertStringContainsString('/SMask 1 0 R', $image);
            self::assertStringContainsString('/Subtype/Image', $image);
            $maskStream = $document->getPdfObjects()[1]->getStream();
            $imageStream = $document->getPdfObjects()[2]->getStream();
            self::assertSame("\x00\x80", gzuncompress($maskStream));
            self::assertSame("\x00\xff\x00\x00", gzuncompress($imageStream));
            self::assertStringContainsString('/Length ' . strlen((string) $maskStream), $softMask);
            self::assertStringContainsString('/Length ' . strlen((string) $imageStream), $image);
        } finally {
            unlink($path);
        }
    }

    #[DataProvider('opaquePngProvider')]
    public function testPreservesEncodedOpaquePngStreams(int $colorType, string $scanline): void
    {
        $path = tempnam(sys_get_temp_dir(), 'core-resource-png-');
        self::assertNotFalse($path);
        try {
            file_put_contents($path, PngFixtureFactory::createPng(1, 1, $colorType, $scanline));
            $document = new PdfDocument();
            (new SignerPhpCoreXObjectResourceBuilder())->materialize($document, [
                'XObject' => ['Im1' => ['Source' => $path]],
            ]);
            self::assertCount(1, $document->getPdfObjects());
            $object = $document->getPdfObjects()[1];
            self::assertSame($scanline, gzuncompress($object->getStream()));
            self::assertStringContainsString('/Length ' . strlen((string) $object->getStream()), $object->toPdfEntry());
        } finally {
            unlink($path);
        }
    }

    /** @return iterable<string, array{int, string}> */
    public static function opaquePngProvider(): iterable
    {
        yield 'grayscale' => [0, "\x00\x7f"];
        yield 'RGB' => [2, "\x00\xff\x00\x00"];
    }

    public function testEmbedsSvgFormResourceInSamePdfDocument(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'core-resource-svg-');
        self::assertNotFalse($path);
        try {
            file_put_contents(
                $path,
                '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="8">'
                . '<rect x="0" y="0" width="12" height="8" fill="#ff0000" /></svg>',
            );
            $document = new PdfDocument();
            $result = (new SignerPhpCoreXObjectResourceBuilder())->materialize($document, [
                'XObject' => ['S1' => ['Source' => $path]],
            ]);
            self::assertSame('1 0 R', (string) $result['XObject']['S1']);
            $form = $document->getPdfObjects()[1];
            self::assertStringContainsString('/Subtype/Form', $form->toPdfEntry());
            self::assertStringContainsString("\nf\n", $form->getStream(false));
        } finally {
            unlink($path);
        }
    }

    public function testRejectsInvalidFontMappingsWithoutCreatingPdfObjects(): void
    {
        $this->withFont(function (TrueTypeFont $font): void {
            $document = new PdfDocument();
            $this->expectException(InvalidArgumentException::class);
            try {
                (new SignerPhpCoreXObjectResourceBuilder())->materialize($document, [
                    'Font' => ['U1' => ['__TrueTypeFont' => $font, '__Mappings' => []]],
                ]);
            } finally {
                self::assertSame([], $document->getPdfObjects());
            }
        });
    }

    public function testFailureInLaterFontLeavesExistingDocumentUnchanged(): void
    {
        $this->withFont(function (TrueTypeFont $font): void {
            $document = new PdfDocument();
            $original = $document->createObject(['Type' => '/Catalog']);
            $document->setMaxOid(10);
            $valid = [
                '__TrueTypeFont' => $font,
                '__Mappings' => [['glyph' => 0x41, 'unicode' => [0x41]]],
            ];
            $invalid = ['__TrueTypeFont' => $font, '__Mappings' => []];
            $builder = new SignerPhpCoreXObjectResourceBuilder();
            try {
                $builder->materialize($document, ['Font' => ['U1' => $valid, 'U2' => $invalid]]);
                self::fail('Invalid font mappings must be rejected.');
            } catch (InvalidArgumentException $error) {
                self::assertSame('Invalid Unicode font mappings.', $error->getMessage());
            }
            self::assertSame([1 => $original], $document->getPdfObjects());
            self::assertSame(10, $document->getMaxOid());
            $result = $builder->materialize($document, ['Font' => ['U1' => $valid]]);
            self::assertSame('16 0 R', (string) $result['Font']['U1']);
        });
    }

    public function testRejectsNullFontPlaceholder(): void
    {
        $document = new PdfDocument();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Unicode font resource.');
        (new SignerPhpCoreXObjectResourceBuilder())->materialize($document, [
            'Font' => ['U1' => ['__TrueTypeFont' => null]],
        ]);
    }

    #[DataProvider('invalidResourceDictionaryProvider')]
    public function testRejectsInvalidResourceDictionary(string $key, mixed $invalid): void
    {
        $document = new PdfDocument();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('resources must');
        (new SignerPhpCoreXObjectResourceBuilder())->materialize($document, [$key => $invalid]);
    }

    /** @return iterable<string, array{key: string, invalid: mixed}> */
    public static function invalidResourceDictionaryProvider(): iterable
    {
        yield 'font string' => ['Font', 'invalid'];
        yield 'image string' => ['XObject', 'invalid'];
        yield 'font numeric key' => ['Font', [7 => []]];
        yield 'image numeric key' => ['XObject', [7 => []]];
    }

    #[DataProvider('invalidImageResourceProvider')]
    public function testRejectsInvalidImageSource(mixed $source): void
    {
        $document = new PdfDocument();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty Source');
        (new SignerPhpCoreXObjectResourceBuilder())->materialize($document, [
            'XObject' => ['Im1' => ['Source' => $source]],
        ]);
    }

    /** @return iterable<string, array{source: mixed}> */
    public static function invalidImageResourceProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'null' => [null];
        yield 'integer' => [1];
        yield 'false' => [false];
        yield 'array' => [[]];
    }

    public function testMaterializesMultipleImagesAndPreservesNonImageResources(): void
    {
        $calls = [];
        $embedder = new class ($calls) implements PdfImageEmbedderInterface {
            /** @var list<string> */
            public array $sources = [];
            public function __construct(array &$calls)
            {
                $this->sources = &$calls;
            }

            public function embed(string $source): EmbeddedPdfImage
            {
                $this->sources[] = $source;
                return new EmbeddedPdfImage(
                    dictionary: ['Type' => '/XObject', 'Subtype' => '/Form', 'BBox' => [0, 0, 1, 1]],
                    stream: 'q /' . $source . ' Q',
                );
            }
        };
        $builder = new SignerPhpCoreXObjectResourceBuilder($embedder);
        $document = new PdfDocument();
        $resources = [
            'ExtGState' => ['GS0' => ['Type' => '/ExtGState']],
            'Font' => ['F1' => new PDFValueReference(42)],
            'XObject' => [
                'preserved' => ['Type' => '/XObject'],
                'ImA' => ['Source' => 'A'],
                'ImB' => ['Source' => 'B'],
                'legacy' => new PDFValueReference(42),
            ],
        ];
        $mapped = ($builder->resourcesBuilder())($document, $resources);
        self::assertSame(['A', 'B'], $calls);
        self::assertSame($resources['ExtGState'], $mapped['ExtGState']);
        self::assertSame($resources['Font'], $mapped['Font']);
        self::assertSame($resources['XObject']['preserved'], $mapped['XObject']['preserved']);
        self::assertSame('1 0 R', (string) $mapped['XObject']['ImA']);
        self::assertSame('2 0 R', (string) $mapped['XObject']['ImB']);
        self::assertSame($resources['XObject']['legacy'], $mapped['XObject']['legacy']);
        self::assertCount(2, $document->getPdfObjects());
        self::assertStringContainsString('/A', $document->getPdfObjects()[1]->getStream(false));
        self::assertStringContainsString('/B', $document->getPdfObjects()[2]->getStream(false));
    }

    public function testStreamObjectsHaveCorrectLengthAndBinaryContent(): void
    {
        $this->withFont(function (TrueTypeFont $font): void {
            $document = new PdfDocument();
            (new SignerPhpCoreXObjectResourceBuilder())->materialize($document, [
                'Font' => ['U1' => [
                    '__TrueTypeFont' => $font,
                    '__Mappings' => [['glyph' => 0x41, 'unicode' => [0x41]]],
                ]],
            ]);
            $object = $document->getPdfObjects()[1];
            self::assertStringContainsString('/Length ' . strlen($font->fontData()), $object->toPdfEntry());
            self::assertSame($font->fontData(), $object->getStream(false));
        });
    }

    #[DataProvider('invalidReferenceProvider')]
    public function testReferenceParserRejectsMalformedReferences(string $value): void
    {
        $builder = new SignerPhpCoreXObjectResourceBuilder();
        $method = new \ReflectionMethod($builder, 'asReference');
        $this->expectException(InvalidArgumentException::class);
        $method->invoke($builder, $value);
    }

    /** @return iterable<string, array{value: string}> */
    public static function invalidReferenceProvider(): iterable
    {
        yield 'leading garbage' => ['prefix12 0 R'];
        yield 'trailing garbage' => ['12 0 R suffix'];
        yield 'trailing newline' => ["12 0 R\n"];
        yield 'zero oid' => ['0 0 R'];
        yield 'generation one' => ['12 1 R'];
        yield 'not a reference' => ['12'];
    }

    #[DataProvider('plainValueProvider')]
    public function testLiteralPdfValueIsNotMistakenForObjectReference(mixed $value): void
    {
        $builder = new SignerPhpCoreXObjectResourceBuilder();
        $method = new \ReflectionMethod($builder, 'convertValue');
        self::assertSame($value, $method->invoke($builder, $value));
    }

    /** @return iterable<string, array{value: mixed}> */
    public static function plainValueProvider(): iterable
    {
        yield 'leading garbage' => ['prefix12 0 R'];
        yield 'trailing garbage' => ['12 0 R suffix'];
        yield 'trailing newline' => ["12 0 R\n"];
        yield 'ordinary literal' => ['text'];
        yield 'zero oid' => ['0 0 R'];
        yield 'true' => [true];
        yield 'integer' => [15];
        yield 'float' => [1.5];
    }

    public function testConvertsNestedReferencesAndRejectsUnsupportedValues(): void
    {
        $builder = new SignerPhpCoreXObjectResourceBuilder();
        $method = new \ReflectionMethod($builder, 'convertValue');
        $value = $method->invoke($builder, ['ref' => '3 0 R', 'other' => [true, 4]]);
        self::assertSame('3 0 R', (string) $value['ref']);
        self::assertSame([true, 4], $value['other']);

        $this->expectException(InvalidArgumentException::class);
        $method->invoke($builder, new \stdClass());
    }

    private function withFont(callable $callback): void
    {
        $path = tempnam(sys_get_temp_dir(), 'core-unicode-font-');
        self::assertNotFalse($path);
        try {
            file_put_contents($path, SyntheticTrueTypeFontTest::makeFont());
            $callback(new TrueTypeFont($path));
        } finally {
            unlink($path);
        }
    }
}
