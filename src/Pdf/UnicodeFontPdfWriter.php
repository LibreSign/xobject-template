<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf;

use Closure;
use InvalidArgumentException;

/** @internal Serializes the indirect objects required by one embedded Type0 font. */
final readonly class UnicodeFontPdfWriter
{
    /**
     * The callbacks use the exporter's PDF object allocator and serializer.
     */
    public function __construct(
        private Closure $stream,
        private Closure $dictionary,
        private UnicodeScalarCodec $codec = new UnicodeScalarCodec(),
    ) {
    }

    /**
     * @param array<int, string|null> $objects
     * @param array<string, mixed> $resource
     */
    public function create(array &$objects, array $resource): string
    {
        $font = $resource['__TrueTypeFont'] ?? null;
        if (!$font instanceof TrueTypeFont) {
            throw new InvalidArgumentException('Invalid Unicode font resource.');
        }
        $mappings = $this->requireMappings($resource['__Mappings'] ?? null);

        $fontName = 'LibreSignUnicode' . substr(hash('sha256', $font->fontData()), 0, 12);
        $fontRef = $this->createFontFile($objects, $font->fontData());
        [$widths, $cidMap, $cmapEntries] = $this->collectMappings($font, $mappings);
        $cidMapRef = $this->createStream($objects, [], $cidMap);
        $toUnicodeRef = $this->createStream($objects, [], $this->createToUnicode($cmapEntries));
        $descriptor = $font->descriptor($fontName, $fontRef);
        $descriptorRef = $this->createDictionary($objects, $descriptor);
        $cidFontRef = $this->createDictionary($objects, [
            'Type' => '/Font',
            'Subtype' => '/CIDFontType2',
            'BaseFont' => '/' . $fontName,
            'CIDSystemInfo' => ['Registry' => 'Adobe', 'Ordering' => 'Identity', 'Supplement' => 0],
            'FontDescriptor' => $descriptorRef,
            'CIDToGIDMap' => $cidMapRef,
            'DW' => 1000,
            'W' => [1, $widths],
        ]);
        return $this->createDictionary($objects, [
            'Type' => '/Font',
            'Subtype' => '/Type0',
            'BaseFont' => '/' . $fontName,
            'Encoding' => '/Identity-H',
            'DescendantFonts' => [$cidFontRef],
            'ToUnicode' => $toUnicodeRef,
        ]);
    }

    /**
     * @return list<array{glyph: int, unicode: list<int>}>
     */
    private function requireMappings(mixed $value): array
    {
        if (!is_array($value) || $value === [] || !array_is_list($value)) {
            throw new InvalidArgumentException('Invalid Unicode font mappings.');
        }
        foreach (array_keys($value) as $index) {
            $this->assertMapping($value[$index]);
        }
        /** @var list<array{glyph: int, unicode: list<int>}> $value */
        return $value;
    }

    private function assertMapping(mixed $entry): void
    {
        if (
            !is_array($entry) || !isset($entry['glyph'], $entry['unicode'])
            || !is_int($entry['glyph']) || !is_array($entry['unicode'])
            || !array_is_list($entry['unicode']) || $entry['unicode'] === []
        ) {
            throw new InvalidArgumentException('Invalid Unicode font mappings.');
        }
        if (count(array_filter($entry['unicode'], 'is_int')) !== count($entry['unicode'])) {
            throw new InvalidArgumentException('Invalid Unicode scalar mapping.');
        }
    }

    /**
     * @param array<int, string|null> $objects
     */
    private function createFontFile(array &$objects, string $data): string
    {
        return $this->createStream($objects, ['Length1' => strlen($data)], $data);
    }

    /**
     * @param list<array{glyph: int, unicode: list<int>}> $mappings
     * @return array{list<int>, string, list<string>}
     */
    private function collectMappings(TrueTypeFont $font, array $mappings): array
    {
        $cidMap = pack('n', 0);
        $widths = [];
        $cmapEntries = [];
        foreach ($mappings as $index => $entry) {
            if ($index > 65534) {
                throw new InvalidArgumentException('Invalid Unicode CID.');
            }
            $glyph = $font->glyphFor($entry['glyph']);
            if ($glyph <= 0 || $glyph > 65535) {
                throw new InvalidArgumentException('Missing TrueType glyph for Unicode resource.');
            }
            $cidMap .= pack('n', $glyph);
            $widths[] = $font->widthForGlyph($glyph);
            $unicodeHex = '';
            foreach ($entry['unicode'] as $codepoint) {
                $unicodeHex .= $this->codec->encodeUtf16Hex($codepoint);
            }
            $cmapEntries[] = sprintf('<%04X> <%s>', $index + 1, $unicodeHex);
        }
        return [$widths, $cidMap, $cmapEntries];
    }

    /** @param list<string> $entries */
    private function createToUnicode(array $entries): string
    {
        $cmap = "/CIDInit /ProcSet findresource begin\n"
            . "12 dict begin\nbegincmap\n"
            . "/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
            . "/CMapName /LibreSignToUnicode def\n/CMapType 2 def\n"
            . "1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n";
        foreach (array_chunk($entries, 100) as $chunk) {
            $cmap .= count($chunk) . " beginbfchar\n"
                . implode("\n", $chunk) . "\nendbfchar\n";
        }
        return $cmap . "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n";
    }

    /**
     * @param array<int, string|null> $objects
     * @param-out array<int, string|null> $objects
     * @param array<string, mixed> $dictionary
     */
    private function createDictionary(array &$objects, array $dictionary): string
    {
        $objectId = count($objects) + 1;
        $objects[$objectId] = (string) ($this->dictionary)($dictionary);
        return sprintf('%d 0 R', $objectId);
    }

    /**
     * @param array<int, string|null> $objects
     * @param-out array<int, string|null> $objects
     * @param array<string, mixed> $dictionary
     */
    private function createStream(array &$objects, array $dictionary, string $data): string
    {
        $objectId = count($objects) + 1;
        $objects[$objectId] = (string) ($this->stream)($dictionary, $data);
        return sprintf('%d 0 R', $objectId);
    }
}
