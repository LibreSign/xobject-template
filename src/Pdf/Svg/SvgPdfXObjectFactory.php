<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf\Svg;

use DOMDocument;
use DOMElement;
use InvalidArgumentException;
use LibreSign\XObjectTemplate\Pdf\ColorParser;
use LibreSign\XObjectTemplate\Pdf\EmbeddedPdfImage;

final readonly class SvgPdfXObjectFactory implements SvgPdfXObjectFactoryInterface
{
    public function __construct(
        private ColorParser $colorParser = new ColorParser(),
        private SvgColorResolver $colorResolver = new SvgColorResolver(),
        private SvgTransformResolver $transformResolver = new SvgTransformResolver(),
        private SvgElementPathBuilder $elementPathBuilder = new SvgElementPathBuilder(),
    ) {
    }

    public function create(string $svgContents, string $source): EmbeddedPdfImage
    {
        $svg = $this->parseSvgRoot($svgContents, $source);
        [$minX, $minY, $width, $height] = $this->resolveViewBox($svg, $source);
        $maxY = $minY + $height;

        [$classFills, $classStrokes] = $this->extractClassColorMaps($svg);
        $commands = [];

        foreach ($this->iterateDrawableElements($svg) as $element) {
            $transformMatrix = $this->transformResolver->resolveElementTransformMatrix($element);
            $path = $this->elementPathBuilder->buildElementPath($element, $minX, $maxY, $source, $transformMatrix);
            if ($path === null) {
                continue;
            }

            $fillColor  = $this->colorResolver->resolveFillColor($element, $classFills);
            $strokeColor = $this->colorResolver->resolveStrokeColor($element, $classStrokes);

            if ($fillColor === null && $strokeColor === null) {
                continue;
            }

            $commands[] = 'q';

            if ($fillColor !== null) {
                $commands[] = $this->colorParser->toPdfRgb($fillColor);
            }

            if ($strokeColor !== null) {
                $commands[] = $this->colorParser->toPdfStrokeRgb($strokeColor);
                $commands[] = sprintf('%F w', $this->resolveStrokeWidth($element));
            }

            $commands[] = $path;

            $commands[] = match (true) {
                $fillColor !== null && $strokeColor !== null => 'B',
                $fillColor !== null => 'f',
                default => 'S',
            };

            $commands[] = 'Q';
        }

        return new EmbeddedPdfImage(
            dictionary: [
                'Type' => '/XObject',
                'Subtype' => '/Form',
                'FormType' => 1,
                'BBox' => [0.0, 0.0, $width, $height],
            ],
            stream: implode("\n", $commands),
        );
    }

    private function parseSvgRoot(string $svgContents, string $source): DOMElement
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previousErrors = libxml_use_internal_errors(true);

        try {
            $parsed = $svgContents !== ''
                && $document->loadXML($svgContents, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }

        $root = $document->documentElement;
        if (!$parsed || $root === null || strtolower((string) $root->localName) !== 'svg') {
            throw new InvalidArgumentException(sprintf('Unable to parse SVG source "%s".', $source));
        }

        return $root;
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function resolveViewBox(DOMElement $svg, string $source): array
    {
        $viewBox = trim($svg->getAttribute('viewBox'));
        if ($viewBox !== '') {
            $parts = preg_split('/[\s,]+/', $viewBox);
            if (!is_array($parts) || count($parts) !== 4) {
                throw new InvalidArgumentException(sprintf('Invalid viewBox in SVG source "%s".', $source));
            }

            $minX = (float) $parts[0];
            $minY = (float) $parts[1];
            $width = (float) $parts[2];
            $height = (float) $parts[3];

            if ($width <= 0.0 || $height <= 0.0) {
                throw new InvalidArgumentException(sprintf('SVG source "%s" must define a positive viewBox.', $source));
            }

            return [$minX, $minY, $width, $height];
        }

        $width = $this->extractNumericSvgLength($svg->getAttribute('width'));
        $height = $this->extractNumericSvgLength($svg->getAttribute('height'));

        if ($width <= 0.0 || $height <= 0.0) {
            throw new InvalidArgumentException(sprintf(
                'SVG source "%s" must define either a valid viewBox or positive width/height.',
                $source,
            ));
        }

        return [0.0, 0.0, $width, $height];
    }

    private function extractNumericSvgLength(string $value): float
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return 0.0;
        }

        if (preg_match('/^[-+]?\d*\.?\d+(?:[eE][-+]?\d+)?/', $trimmed, $matches) !== 1) {
            return 0.0;
        }

        return (float) $matches[0];
    }

    /**
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    /**
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function extractClassColorMaps(DOMElement $svg): array
    {
        $fills   = [];
        $strokes = [];

        foreach ($svg->getElementsByTagName('style') as $styleNode) {
            $css = $styleNode->textContent;
            if (!is_string($css) || $css === '') {
                continue;
            }

            if (preg_match_all('/\.([a-zA-Z0-9_-]+)\s*\{([^}]*)\}/', $css, $rules, PREG_SET_ORDER) !== false) {
                foreach ($rules as $rule) {
                    if (preg_match('/(?:^|;)\s*fill\s*:\s*([^;]+)/i', $rule[2], $fillMatch) === 1) {
                        $color = $this->colorResolver->normalizeColor($fillMatch[1]);
                        if ($color !== null) {
                            $fills[$rule[1]] = $color;
                        }
                    }

                    if (preg_match('/(?:^|;)\s*stroke\s*:\s*([^;]+)/i', $rule[2], $strokeMatch) === 1) {
                        $color = $this->colorResolver->normalizeColor($strokeMatch[1]);
                        if ($color !== null) {
                            $strokes[$rule[1]] = $color;
                        }
                    }
                }
            }
        }

        return [$fills, $strokes];
    }

    /**
     * @return list<DOMElement>
     */
    private function iterateDrawableElements(DOMElement $svg): array
    {
        $elements = [];

        foreach ($svg->getElementsByTagName('*') as $element) {
            if (!$element instanceof DOMElement) {
                continue;
            }

            $name = strtolower((string) $element->localName);
            if (in_array($name, ['path', 'polygon', 'polyline', 'rect', 'circle', 'ellipse', 'line'], true)) {
                $elements[] = $element;
            }
        }

        return $elements;
    }

    private function resolveStrokeWidth(DOMElement $element): float
    {
        $attr = trim($element->getAttribute('stroke-width'));
        if ($attr !== '') {
            return max(0.0, $this->extractNumericSvgLength($attr));
        }

        $styleWidth = $this->colorResolver->extractValueFromStyleAttribute($element->getAttribute('style'), 'stroke-width');
        if ($styleWidth !== null) {
            return max(0.0, $this->extractNumericSvgLength($styleWidth));
        }

        return 1.0;
    }

}
