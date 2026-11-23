<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf;

use Closure;
use InvalidArgumentException;
use SignerPHP\PdfCore\PdfDocument;
use SignerPHP\PdfCore\PdfValue\PDFValueReference;
use SignerPHP\PdfCore\PdfValue\PDFValueString;

/**
 * Materializes Unicode fonts and compiled images in the signature PDF document.
 *
 * The exported resource array cannot contain internal font or image placeholders.
 * Call resourcesBuilder() when constructing an AppearanceXObject supporting resource builders.
 */
final readonly class SignerPhpCoreXObjectResourceBuilder
{
    private PdfImageEmbedderInterface $imageEmbedder;

    public function __construct(?PdfImageEmbedderInterface $imageEmbedder = null)
    {
        $this->imageEmbedder = $imageEmbedder ?? new FilesystemPdfImageEmbedder();
    }

    /**
     * @return Closure(PdfDocument, array<string, mixed>): array<string, mixed>
     */
    public function resourcesBuilder(): Closure
    {
        return $this->materialize(...);
    }

    /**
     * @param array<string, mixed> $resources
     * @return array<string, mixed>
     */
    public function materialize(PdfDocument $document, array $resources): array
    {
        $fonts = $this->requireDictionary($resources['Font'] ?? [], 'Font');
        $images = $this->requireDictionary($resources['XObject'] ?? [], 'XObject');
        $preparedImages = $this->prepareImages($images);
        $resources['Font'] = $this->materializeFonts($document, $fonts);

        if ($images !== []) {
            $resources['XObject'] = $this->materializeImages($document, $images, $preparedImages);
        }

        return $resources;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireDictionary(mixed $value, string $name): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException(sprintf('XObject %s resources must be a dictionary.', $name));
        }
        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                throw new InvalidArgumentException(sprintf('XObject %s resources must have string keys.', $name));
            }
        }
        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $images
     * @return array<string, EmbeddedPdfImage>
     */
    private function prepareImages(array $images): array
    {
        $prepared = [];
        foreach ($images as $alias => $resource) {
            if (!is_array($resource) || !isset($resource['Source'])) {
                continue;
            }
            if (!is_string($resource['Source']) || $resource['Source'] === '') {
                throw new InvalidArgumentException('Image XObject requires a non-empty Source.');
            }
            $prepared[$alias] = $this->imageEmbedder->embed($resource['Source']);
        }
        return $prepared;
    }

    /**
     * @param array<string, mixed> $fonts
     * @return array<string, mixed>
     */
    private function materializeFonts(PdfDocument $document, array $fonts): array
    {
        $makeDictionary = fn (array $dictionary): string => $this->createDictionary(
            $document,
            $this->requireDictionary($dictionary, 'generated'),
        );
        $makeStream = fn (array $dictionary, string $data): string => $this->createStream(
            $document,
            $this->requireDictionary($dictionary, 'generated'),
            $data,
        );
        $writer = new UnicodeFontPdfWriter($makeStream, $makeDictionary);

        foreach ($fonts as $alias => $font) {
            if (!is_array($font) || !isset($font['__TrueTypeFont'])) {
                continue;
            }
            $reference = $writer->createWithFactories(
                $this->requireDictionary($font, 'Font'),
                $makeDictionary,
                $makeStream,
            );
            $fonts[$alias] = $this->asReference($reference);
        }
        return $fonts;
    }

    /**
     * @param array<string, mixed> $images
     * @param array<string, EmbeddedPdfImage> $preparedImages
     * @return array<string, mixed>
     */
    private function materializeImages(PdfDocument $document, array $images, array $preparedImages): array
    {
        foreach ($preparedImages as $alias => $image) {
            $images[$alias] = $this->createImageObject($document, $image);
        }
        return $images;
    }

    private function createImageObject(PdfDocument $document, EmbeddedPdfImage $image): PDFValueReference
    {
        $dictionary = $image->dictionary;
        if ($image->softMask !== null) {
            $dictionary['SMask'] = $this->createStream(
                $document,
                $image->softMask->dictionary,
                $image->softMask->stream,
            );
        }
        return $this->asReference($this->createStream($document, $dictionary, $image->stream));
    }

    /**
     * @param array<string, mixed> $dictionary
     */
    private function createDictionary(PdfDocument $document, array $dictionary): string
    {
        $object = $document->createObject($this->convertDictionary($dictionary));
        return sprintf('%d 0 R', $object->getOid());
    }

    /**
     * @param array<string, mixed> $dictionary
     */
    private function createStream(PdfDocument $document, array $dictionary, string $bytes): string
    {
        $object = $document->createObject($this->convertDictionary($dictionary));
        $object->setStream($bytes, false);
        return sprintf('%d 0 R', $object->getOid());
    }

    /**
     * @param array<string, mixed> $dictionary
     * @return array<string, mixed>
     */
    /** @psalm-suppress MixedAssignment Generated PDF resources contain dynamic scalar and nested values. */
    private function convertDictionary(array $dictionary): array
    {
        $converted = [];
        foreach ($dictionary as $key => $value) {
            if ($key === 'CIDSystemInfo' && is_array($value)) {
                $converted[$key] = [
                    'Registry' => new PDFValueString($value['Registry']),
                    'Ordering' => new PDFValueString($value['Ordering']),
                    'Supplement' => $value['Supplement'],
                ];
                continue;
            }
            $converted[$key] = $this->convertValue($value);
        }
        return $converted;
    }

    /**
     * @return PDFValueReference|array<array-key, mixed>|string|int|float|bool
     * @psalm-suppress MixedAssignment Dynamic PDF dictionaries are validated as they are converted.
     */
    private function convertValue(mixed $value): PDFValueReference|array|string|int|float|bool
    {
        if (is_string($value) && preg_match('/^[1-9][0-9]* 0 R$/D', $value) === 1) {
            return $this->asReference($value);
        }
        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }
        if (!is_array($value)) {
            throw new InvalidArgumentException('Unsupported value in generated PDF resource.');
        }
        $converted = [];
        foreach ($value as $key => $item) {
            $converted[$key] = $this->convertValue($item);
        }
        return $converted;
    }

    private function asReference(string $reference): PDFValueReference
    {
        if (preg_match('/^[1-9][0-9]* 0 R$/D', $reference) !== 1) {
            throw new InvalidArgumentException('Invalid generated PDF object reference.');
        }
        return new PDFValueReference(strtok($reference, ' '));
    }
}
