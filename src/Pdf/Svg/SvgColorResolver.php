<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace LibreSign\XObjectTemplate\Pdf\Svg;

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
        $classes = preg_split('/\s+/', trim($element->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($classes as $class) {
            if (isset($classColors[$class])) {
                return $classColors[$class] === 'none' ? null : $classColors[$class];
            }
        }

        return $this->checkAncestorForColor($element, $attributeName, $defaultFallback);
    }

    /**
     * Walk ancestor elements looking for an inherited color value.
     */
    private function checkAncestorForColor(DOMElement $element, string $attributeName, ?string $defaultFallback): ?string
    {
        $ancestor = $element->parentNode;
        while ($ancestor instanceof DOMElement) {
            $ancestorColor = $this->normalizeColor($ancestor->getAttribute($attributeName));
            if ($ancestorColor !== null) {
                return $ancestorColor === 'none' ? null : $ancestorColor;
            }

            $ancestorStyle = $this->extractColorFromStyleAttribute($ancestor->getAttribute('style'), $attributeName);
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
            $declaration = trim($declaration);
            if ($declaration === '') {
                continue;
            }

            if (preg_match('/^' . preg_quote($property) . '\s*:\s*(.+)$/i', $declaration, $matches) === 1) {
                return trim($matches[1]);
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

        if (preg_match('/^#[0-9a-f]{3}([0-9a-f]{3})?$/i', $trimmed) === 1) {
            return $trimmed;
        }

        if (preg_match('/^rgb\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)$/', $trimmed, $matches) === 1) {
            $red   = max(0, min(255, (int) $matches[1]));
            $green = max(0, min(255, (int) $matches[2]));
            $blue  = max(0, min(255, (int) $matches[3]));

            return sprintf('#%02x%02x%02x', $red, $green, $blue);
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
}
