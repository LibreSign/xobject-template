<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace LibreSign\XObjectTemplate\Pdf\Svg;

use function array_filter;
use function array_map;
use DOMElement;

/**
 * Resolves and normalizes colors in SVG elements.
 *
 * This class encapsulates the logic for extracting color information from
 * SVG attributes, styles, and CSS class definitions. It handles cascading
 * color resolution with support for inline attributes, style properties,
 * and ancestor inheritance.
 */
final class SvgColorResolver
{
    /**
     * Resolve fill color for an SVG element.
     *
     * Checks inline fill attribute, style attribute, CSS classes, and
     * ancestor elements in order.
     *
     * @param DOMElement $element The SVG element to resolve
     * @param array<string, string> $classFills Map of CSS class names to fill colors
     * @return ?string The resolved fill color, or null if no fill
     */
    public function resolveFillColor(DOMElement $element, array $classFills): ?string
    {
        return $this->resolveColorAttribute($element, 'fill', $classFills, '#000000');
    }

    /**
     * Resolve stroke color for an SVG element.
     *
     * Checks inline stroke attribute, style attribute, CSS classes, and
     * ancestor elements in order.
     *
     * @param DOMElement $element The SVG element to resolve
     * @param array<string, string> $classStrokes Map of CSS class names to stroke colors
     * @return ?string The resolved stroke color, or null if no stroke
     */
    public function resolveStrokeColor(DOMElement $element, array $classStrokes): ?string
    {
        return $this->resolveColorAttribute($element, 'stroke', $classStrokes, null);
    }

    /**
     * Resolve a generic color attribute with inheritance.
     *
     * Implements SVG color resolution with cascading fallback:
     * 1. Inline attribute (highest priority)
     * 2. Style attribute
     * 3. CSS class definitions
     * 4. Ancestor attributes (inheritance chain)
     * 5. Default fallback (lowest priority)
     *
     * @param DOMElement $element The SVG element
     * @param string $attributeName The color attribute name (fill/stroke)
     * @param array<string, string> $classColors CSS class to color mappings
     * @param ?string $defaultFallback Default color if no other source found
     * @return ?string The resolved color, or null
     */
    public function resolveColorAttribute(
        DOMElement $element,
        string $attributeName,
        array $classColors,
        ?string $defaultFallback,
    ): ?string {
        // Check inline attribute
        $inlineColor = $this->normalizeColor($element->getAttribute($attributeName));
        if ($inlineColor === 'none') {
            return null;
        }

        if ($inlineColor !== null) {
            return $inlineColor;
        }

        // Check inline style attribute
        $inlineStyle = $this->extractColorFromStyleAttribute($element->getAttribute('style'), $attributeName);
        if ($inlineStyle === 'none') {
            return null;
        }

        if ($inlineStyle !== null) {
            return $inlineStyle;
        }

        // Check CSS classes
        $classes = $this->extractClasses($element->getAttribute('class'));

        foreach ($classes as $class) {
            if (isset($classColors[$class])) {
                return $classColors[$class] === 'none' ? null : $classColors[$class];
            }
        }

        return $this->checkAncestorForColor(
            $element,
            $attributeName,
            $defaultFallback,
        );
    }

    /**
     * Walk ancestor elements looking for an inherited color value.
     */
    private function checkAncestorForColor(
        DOMElement $element,
        string $attributeName,
        ?string $defaultFallback,
    ): ?string {
        $ancestor = $element->parentNode;
        while ($ancestor instanceof DOMElement) {
            $ancestorColor = $this->normalizeColor($ancestor->getAttribute($attributeName));
            if ($ancestorColor !== null) {
                return $ancestorColor === 'none' ? null : $ancestorColor;
            }

            $ancestorStyle = $this->extractColorFromStyleAttribute(
                $ancestor->getAttribute('style'),
                $attributeName,
            );
            if ($ancestorStyle !== null) {
                return $ancestorStyle === 'none' ? null : $ancestorStyle;
            }

            $ancestor = $ancestor->parentNode;
        }

        return $defaultFallback;
    }

    /**
     * Extract color from a CSS style attribute.
     *
     * Parses style declarations to extract specific color properties.
     *
     * @param string $style The inline style attribute value
     * @param string $property The property name to extract (fill/stroke)
     * @return ?string The color value or null if not found
     */
    public function extractColorFromStyleAttribute(string $style, string $property): ?string
    {
        return $this->extractValueFromStyleAttribute($style, $property);
    }

    /**
     * Extract a generic value from a CSS style attribute.
     *
     * Parses semicolon-separated style declarations to extract property values.
     *
     * @param string $style The inline style attribute value
     * @param string $property The property name to extract
     * @return ?string The property value or null if not found
     */
    public function extractValueFromStyleAttribute(string $style, string $property): ?string
    {
        if ($style === '') {
            return null;
        }

        $declarations = preg_split('/;/', $style);
        if (!is_array($declarations)) {
            return null;
        }

        foreach ($declarations as $declaration) {
            if (trim($declaration) === '') {
                continue;
            }

            $parts = explode(':', $declaration, 2);
            if (count($parts) !== 2) {
                continue;
            }

            [$candidateProperty, $candidateValue] = array_map(trim(...), $parts);
            if (strcasecmp($candidateProperty, $property) === 0) {
                return $candidateValue;
            }
        }

        return null;
    }

    /**
     * Normalize and validate a color value.
     *
     * Handles trimming, case normalization, hex colors, rgb() values,
     * and named colors. Returns null for empty or invalid colors.
     *
     * @param string $color The color value to normalize
     * @return ?string The normalized color or null/special 'none' sentinel
     */
    public function normalizeColor(string $color): ?string
    {
        $trimmed = strtolower(trim($color));
        if ($trimmed === '') {
            return null;
        }

        if ($trimmed === 'none') {
            return 'none';
        }

        if ($this->isHexColor($trimmed)) {
            return $trimmed;
        }

        $rgb = $this->parseRgbColor($trimmed);
        if ($rgb !== null) {
            return sprintf('#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]);
        }

        return match ($trimmed) {
            'black' => '#000000',
            'white' => '#ffffff',
            'red' => '#ff0000',
            'green' => '#008000',
            'blue' => '#0000ff',
            'yellow' => '#ffff00',
            'gray', 'grey' => '#808080',
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private function extractClasses(string $classAttribute): array
    {
        $normalized = preg_replace('/\s+/', ' ', trim($classAttribute));
        if (!is_string($normalized) || $normalized === '') {
            return [];
        }

        return array_values(array_filter(explode(' ', $normalized), static fn (string $class): bool => $class !== ''));
    }

    private function isHexColor(string $color): bool
    {
        if (!str_starts_with($color, '#')) {
            return false;
        }

        $hex = substr($color, 1);
        $length = strlen($hex);

        return ($length === 3 || $length === 6) && ctype_xdigit($hex);
    }

    /**
     * @return array{0: int, 1: int, 2: int}|null
     */
    private function parseRgbColor(string $color): ?array
    {
        if (!str_starts_with($color, 'rgb(') || !str_ends_with($color, ')')) {
            return null;
        }

        $parts = array_map(
            static fn (string $part): string => trim($part),
            explode(',', substr($color, 4, -1)),
        );

        if (count($parts) !== 3) {
            return null;
        }

        $channels = [];

        foreach ($parts as $part) {
            if ($part === '' || preg_match('/^\d+$/', $part) !== 1) {
                return null;
            }

            $channel = filter_var($part, FILTER_VALIDATE_INT);
            if (!is_int($channel) || $channel < 0) {
                return null;
            }

            $channels[] = min(255, $channel);
        }

        return [$channels[0], $channels[1], $channels[2]];
    }
}
