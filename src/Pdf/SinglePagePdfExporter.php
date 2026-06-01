<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf;

use InvalidArgumentException;
use LibreSign\XObjectTemplate\Dto\CompileResult;

final readonly class SinglePagePdfExporter
{
    private const PDF_HEADER = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";

    private CompileResultResourceExtractor $resourceExtractor;

    public function __construct(
        private PdfImageEmbedderInterface $imageEmbedder = new FilesystemPdfImageEmbedder(),
        ?CompileResultResourceExtractor $resourceExtractor = null,
    ) {
        $this->resourceExtractor = $resourceExtractor ?? new CompileResultResourceExtractor();
    }

    public function export(CompileResult $result): string
    {
        [$minX, $minY, $maxX, $maxY] = $result->bbox;
        $width = $maxX - $minX;
        $height = $maxY - $minY;

        if ($width <= 0.0 || $height <= 0.0) {
            throw new InvalidArgumentException('CompileResult bbox must describe a positive area.');
        }

        $objects = [];
        $catalogReference = $this->reserveObject($objects);
        $pagesReference = $this->reserveObject($objects);
        $pageReference = $this->reserveObject($objects);
        $pageContentReference = $this->reserveObject($objects);
        $formReference = $this->reserveObject($objects);

        $fontReferences = $this->createFontObjects(
            $objects,
            $this->resourceExtractor->extract($result, 'Font', 'Font resource "%s" must be an array.'),
        );
        $imageReferences = $this->createImageObjects(
            $objects,
            $this->resourceExtractor->extract($result, 'XObject', 'XObject resource "%s" must be an array.'),
        );

        $objects[$catalogReference] = $this->serializeDictionary([
            'Type' => '/Catalog',
            'Pages' => $this->asReference($pagesReference),
        ]);

        $objects[$pagesReference] = $this->serializeDictionary([
            'Type' => '/Pages',
            'Count' => 1,
            'Kids' => [$this->asReference($pageReference)],
        ]);

        $objects[$pageReference] = $this->serializeDictionary([
            'Type' => '/Page',
            'Parent' => $this->asReference($pagesReference),
            'MediaBox' => [0.0, 0.0, $width, $height],
            'Resources' => [
                'XObject' => [
                    'Fm0' => $this->asReference($formReference),
                ],
            ],
            'Contents' => $this->asReference($pageContentReference),
        ]);

        $pageStream = sprintf(
            'q 1 0 0 1 %s %s cm /Fm0 Do Q',
            $this->formatNumber(-$minX),
            $this->formatNumber(-$minY),
        );
        $objects[$pageContentReference] = $this->serializeStreamObject([], $pageStream);

        $formResources = [];
        if ($fontReferences !== []) {
            $formResources['Font'] = $fontReferences;
        }

        if ($imageReferences !== []) {
            $formResources['XObject'] = $imageReferences;
        }

        $objects[$formReference] = $this->serializeStreamObject([
            'Type' => '/XObject',
            'Subtype' => '/Form',
            'FormType' => 1,
            'BBox' => $result->bbox,
            'Resources' => $formResources,
        ], $result->contentStream);

        return $this->renderDocument($objects, $catalogReference);
    }

    /**
     * @param array<int, string|null> $objects
     * @param array<string, array<string, mixed>> $fontResources
     * @return array<string, string>
     */
    private function createFontObjects(array &$objects, array $fontResources): array
    {
        $fontReferences = [];

        foreach ($fontResources as $alias => $fontResource) {
            $reference = $this->reserveObject($objects);
            $objects[$reference] = $this->serializeDictionary($fontResource);
            $fontReferences[$alias] = $this->asReference($reference);
        }

        return $fontReferences;
    }

    /**
     * @param array<int, string|null> $objects
     * @param array<string, array<string, mixed>> $xObjects
     * @return array<string, string>
     */
    private function createImageObjects(array &$objects, array $xObjects): array
    {
        $imageReferences = [];

        foreach ($xObjects as $alias => $resource) {
            $source = $resource['Source'] ?? null;
            if (!is_string($source) || $source === '') {
                throw new InvalidArgumentException(
                    sprintf('XObject resource "%s" must expose a non-empty Source.', $alias),
                );
            }

            $embeddedImage = $this->imageEmbedder->embed($source);
            $softMaskReference = null;
            if ($embeddedImage->softMask !== null) {
                $softMaskRefId = $this->reserveObject($objects);
                $objects[$softMaskRefId] = $this->serializeStreamObject(
                    $embeddedImage->softMask->dictionary,
                    $embeddedImage->softMask->stream,
                );
                $softMaskReference = $this->asReference($softMaskRefId);
            }

            $dictionary = $embeddedImage->dictionary;
            if ($softMaskReference !== null) {
                $dictionary['SMask'] = $softMaskReference;
            }

            $reference = $this->reserveObject($objects);
            $objects[$reference] = $this->serializeStreamObject($dictionary, $embeddedImage->stream);
            $imageReferences[$alias] = $this->asReference($reference);
        }

        return $imageReferences;
    }

    /**
     * @param array<int, string|null> $objects
     */
    private function reserveObject(array &$objects): int
    {
        $reference = count($objects) + 1;
        $objects[$reference] = null;

        return $reference;
    }

    /**
     * @param array<string, mixed> $dictionary
     */
    private function serializeStreamObject(array $dictionary, string $stream): string
    {
        $dictionary['Length'] = strlen($stream);

        return $this->serializeDictionary($dictionary)
            . "\nstream\n"
            . $stream
            . "\nendstream";
    }

    /**
     * @param array<string, mixed> $dictionary
     */
    private function serializeDictionary(array $dictionary): string
    {
        if ($dictionary === []) {
            return '<< >>';
        }

        $entries = [];
        foreach (array_keys($dictionary) as $key) {
            $entries[] = sprintf('/%s %s', $key, $this->serializeValue($dictionary[$key]));
        }

        return '<< ' . implode(' ', $entries) . ' >>';
    }

    private function serializeValue(mixed $value): string
    {
        if (is_array($value)) {
            return $this->serializeArrayValue($value);
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return $this->formatNumber($value);
        }

        if (is_string($value)) {
            return $this->serializeStringValue($value);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        throw new InvalidArgumentException(sprintf('Unsupported PDF value type "%s".', get_debug_type($value)));
    }

    private function serializeArrayValue(array $value): string
    {
        if ($value === []) {
            return '[]';
        }

        if (array_is_list($value)) {
            return '[' . implode(' ', array_map($this->serializeValue(...), $value)) . ']';
        }

        return $this->serializeDictionary(
            $this->requireStringKeyedArray($value, 'PDF dictionaries must use string keys.'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function requireStringKeyedArray(mixed $value, string $message): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException($message);
        }

        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                throw new InvalidArgumentException($message);
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    private function serializeStringValue(string $value): string
    {
        if ($this->isRawPdfValue($value)) {
            return $value;
        }

        return '(' . $this->escapeLiteralString($value) . ')';
    }

    /**
     * @param array<int, string|null> $objects
     */
    private function renderDocument(array $objects, int $catalogReference): string
    {
        ksort($objects);

        $pdf = self::PDF_HEADER;
        $offsets = [];

        foreach ($objects as $reference => $objectBody) {
            if ($objectBody === null) {
                throw new InvalidArgumentException(sprintf('PDF object %d was reserved but not written.', $reference));
            }

            $offsets[$reference] = strlen($pdf);
            $pdf .= sprintf("%d 0 obj\n%s\nendobj\n", $reference, $objectBody);
        }

        $xrefOffset = strlen($pdf);
        $objectCount = count($objects);

        $pdf .= sprintf("xref\n0 %d\n", $objectCount + 1);
        $pdf .= "0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        $pdf .= "trailer\n";
        $pdf .= $this->serializeDictionary([
            'Size' => $objectCount + 1,
            'Root' => $this->asReference($catalogReference),
        ]);
        $pdf .= sprintf("\nstartxref\n%d\n%%%%EOF", $xrefOffset);

        return $pdf;
    }

    private function asReference(int $reference): string
    {
        return sprintf('%d 0 R', $reference);
    }

    private function isRawPdfValue(string $value): bool
    {
        return str_starts_with($value, '/')
            || preg_match('/^\d+ 0 R$/', $value) === 1;
    }

    private function escapeLiteralString(string $value): string
    {
        return str_replace(
            ['\\', '(', ')'],
            ['\\\\', '\\(', '\\)'],
            $value,
        );
    }

    private function formatNumber(float $value): string
    {
        $formatted = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
        if ($formatted === '' || $formatted === '-0') {
            return '0';
        }

        return $formatted;
    }
}
