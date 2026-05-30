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
    public function __construct(private ColorParser $colorParser = new ColorParser())
    {
    }

    public function create(string $svgContents, string $source): EmbeddedPdfImage
    {
        $svg = $this->parseSvgRoot($svgContents, $source);
        [$minX, $minY, $width, $height] = $this->resolveViewBox($svg, $source);
        $maxY = $minY + $height;

        [$classFills, $classStrokes] = $this->extractClassColorMaps($svg);
        $commands = [];

        foreach ($this->iterateDrawableElements($svg) as $element) {
            $transformMatrix = $this->resolveElementTransformMatrix($element);
            $path = $this->buildElementPath($element, $minX, $maxY, $source, $transformMatrix);
            if ($path === null) {
                continue;
            }

            $fillColor  = $this->resolveFillColor($element, $classFills);
            $strokeColor = $this->resolveStrokeColor($element, $classStrokes);

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

            if ($fillColor !== null && $strokeColor !== null) {
                $commands[] = 'B';
            } elseif ($fillColor !== null) {
                $commands[] = 'f';
            } else {
                $commands[] = 'S';
            }

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
                        $color = $this->normalizeColor($fillMatch[1]);
                        if ($color !== null) {
                            $fills[$rule[1]] = $color;
                        }
                    }

                    if (preg_match('/(?:^|;)\s*stroke\s*:\s*([^;]+)/i', $rule[2], $strokeMatch) === 1) {
                        $color = $this->normalizeColor($strokeMatch[1]);
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

    /**
     * @param array{0:float,1:float,2:float,3:float,4:float,5:float} $transformMatrix
     */
    private function buildElementPath(
        DOMElement $element,
        float $minX,
        float $maxY,
        string $source,
        array $transformMatrix,
    ): ?string {
        $name = strtolower((string) $element->localName);

        return match ($name) {
            'path'     => $this->buildPathElementPath($element, $minX, $maxY, $source, $transformMatrix),
            'polygon'  => $this->buildPolygonElementPath($element, $minX, $maxY, $transformMatrix),
            'polyline' => $this->buildPolylineElementPath($element, $minX, $maxY, $transformMatrix),
            'rect'     => $this->buildRectElementPath($element, $minX, $maxY, $transformMatrix),
            'circle'   => $this->buildCircleElementPath($element, $minX, $maxY, $transformMatrix),
            'ellipse'  => $this->buildEllipseElementPath($element, $minX, $maxY, $transformMatrix),
            'line'     => $this->buildLineElementPath($element, $minX, $maxY, $transformMatrix),
            default    => null,
        };
    }

    /**
     * @param array{0:float,1:float,2:float,3:float,4:float,5:float} $transformMatrix
     */
    private function buildPathElementPath(
        DOMElement $element,
        float $minX,
        float $maxY,
        string $source,
        array $transformMatrix,
    ): ?string {
        $d = trim($element->getAttribute('d'));
        if ($d === '') {
            return null;
        }

        return $this->convertPathData($d, $minX, $maxY, $source, $transformMatrix);
    }

    /**
     * @param array{0:float,1:float,2:float,3:float,4:float,5:float} $transformMatrix
     */
    private function buildPolygonElementPath(
        DOMElement $element,
        float $minX,
        float $maxY,
        array $transformMatrix,
    ): ?string {
        $points = trim($element->getAttribute('points'));
        if ($points === '') {
            return null;
        }

        if (preg_match_all('/[-+]?\d*\.?\d+(?:[eE][-+]?\d+)?/', $points, $matches) < 1) {
            return null;
        }

        $raw = $matches[0];
        if (count($raw) < 4 || count($raw) % 2 !== 0) {
            return null;
        }

        $commands = [];
        $x = (float) $raw[0];
        $y = (float) $raw[1];
        [$x, $y] = $this->applyTransformToPoint($transformMatrix, $x, $y);
        $commands[] = sprintf('%F %F m', $x - $minX, $maxY - $y);

        for ($index = 2; $index < count($raw); $index += 2) {
            $px = (float) $raw[$index];
            $py = (float) $raw[$index + 1];
            [$px, $py] = $this->applyTransformToPoint($transformMatrix, $px, $py);
            $commands[] = sprintf('%F %F l', $px - $minX, $maxY - $py);
        }

        $commands[] = 'h';

        return implode("\n", $commands);
    }

    /**
     * @param array{0:float,1:float,2:float,3:float,4:float,5:float} $transformMatrix
     */
    private function buildRectElementPath(
        DOMElement $element,
        float $minX,
        float $maxY,
        array $transformMatrix,
    ): ?string {
        $x = $this->extractNumericSvgLength($element->getAttribute('x'));
        $y = $this->extractNumericSvgLength($element->getAttribute('y'));
        $width = $this->extractNumericSvgLength($element->getAttribute('width'));
        $height = $this->extractNumericSvgLength($element->getAttribute('height'));

        if ($width <= 0.0 || $height <= 0.0) {
            return null;
        }

        $points = [
            [$x, $y],
            [$x + $width, $y],
            [$x + $width, $y + $height],
            [$x, $y + $height],
        ];

        $commands = [];
        foreach ($points as $index => [$px, $py]) {
            [$tx, $ty] = $this->applyTransformToPoint($transformMatrix, $px, $py);
            $commands[] = sprintf('%F %F %s', $tx - $minX, $maxY - $ty, $index === 0 ? 'm' : 'l');
        }

        $commands[] = 'h';

        return implode("\n", $commands);
    }

    /**
     * @param array{0:float,1:float,2:float,3:float,4:float,5:float} $transformMatrix
     */
    private function buildPolylineElementPath(
        DOMElement $element,
        float $minX,
        float $maxY,
        array $transformMatrix,
    ): ?string {
        $points = trim($element->getAttribute('points'));
        if ($points === '') {
            return null;
        }

        if (preg_match_all('/[-+]?\d*\.?\d+(?:[eE][-+]?\d+)?/', $points, $matches) < 1) {
            return null;
        }

        $raw = $matches[0];
        if (count($raw) < 4 || count($raw) % 2 !== 0) {
            return null;
        }

        $commands = [];
        [$firstX, $firstY] = $this->applyTransformToPoint(
            $transformMatrix,
            (float) $raw[0],
            (float) $raw[1],
        );
        $commands[] = sprintf('%F %F m', $firstX - $minX, $maxY - $firstY);

        for ($index = 2; $index < count($raw); $index += 2) {
            [$tx, $ty] = $this->applyTransformToPoint(
                $transformMatrix,
                (float) $raw[$index],
                (float) $raw[$index + 1],
            );
            $commands[] = sprintf('%F %F l', $tx - $minX, $maxY - $ty);
        }

        return implode("\n", $commands);
    }

    /**
     * @param array{0:float,1:float,2:float,3:float,4:float,5:float} $transformMatrix
     */
    private function buildCircleElementPath(
        DOMElement $element,
        float $minX,
        float $maxY,
        array $transformMatrix,
    ): ?string {
        $cx = $this->extractNumericSvgLength($element->getAttribute('cx'));
        $cy = $this->extractNumericSvgLength($element->getAttribute('cy'));
        $r  = $this->extractNumericSvgLength($element->getAttribute('r'));

        if ($r <= 0.0) {
            return null;
        }

        return $this->buildEllipsePath($cx, $cy, $r, $r, $minX, $maxY, $transformMatrix);
    }

    /**
     * @param array{0:float,1:float,2:float,3:float,4:float,5:float} $transformMatrix
     */
    private function buildEllipseElementPath(
        DOMElement $element,
        float $minX,
        float $maxY,
        array $transformMatrix,
    ): ?string {
        $cx = $this->extractNumericSvgLength($element->getAttribute('cx'));
        $cy = $this->extractNumericSvgLength($element->getAttribute('cy'));
        $rx = $this->extractNumericSvgLength($element->getAttribute('rx'));
        $ry = $this->extractNumericSvgLength($element->getAttribute('ry'));

        if ($rx <= 0.0 || $ry <= 0.0) {
            return null;
        }

        return $this->buildEllipsePath($cx, $cy, $rx, $ry, $minX, $maxY, $transformMatrix);
    }

    /**
     * Approximate an axis-aligned ellipse with 4 cubic Bézier curves (κ = 0.5522847498).
     * PDF Y-axis is flipped: top of ellipse is at (cx, cy−ry) in SVG → maxY−(cy−ry) in PDF.
     */
    /**
     * @param array{0:float,1:float,2:float,3:float,4:float,5:float} $transformMatrix
     */
    private function buildEllipsePath(
        float $cx,
        float $cy,
        float $rx,
        float $ry,
        float $minX,
        float $maxY,
        array $transformMatrix,
    ): string {
        $k  = 0.5522847498;
        $kx = $k * $rx;
        $ky = $k * $ry;

        $topX = $cx;
        $topY = $cy - $ry;
        $rightX = $cx + $rx;
        $rightY = $cy;
        $bottomX = $cx;
        $bottomY = $cy + $ry;
        $leftX = $cx - $rx;
        $leftY = $cy;

        [$topX, $topY] = $this->applyTransformToPoint($transformMatrix, $topX, $topY);
        [$rightX, $rightY] = $this->applyTransformToPoint($transformMatrix, $rightX, $rightY);
        [$bottomX, $bottomY] = $this->applyTransformToPoint($transformMatrix, $bottomX, $bottomY);
        [$leftX, $leftY] = $this->applyTransformToPoint($transformMatrix, $leftX, $leftY);

        [$cpTopRight1X, $cpTopRight1Y] = $this->applyTransformToPoint(
            $transformMatrix,
            $cx + $kx,
            $cy - $ry,
        );
        [$cpTopRight2X, $cpTopRight2Y] = $this->applyTransformToPoint(
            $transformMatrix,
            $cx + $rx,
            $cy - $ky,
        );
        [$cpRightBottom1X, $cpRightBottom1Y] = $this->applyTransformToPoint(
            $transformMatrix,
            $cx + $rx,
            $cy + $ky,
        );
        [$cpRightBottom2X, $cpRightBottom2Y] = $this->applyTransformToPoint(
            $transformMatrix,
            $cx + $kx,
            $cy + $ry,
        );
        [$cpBottomLeft1X, $cpBottomLeft1Y] = $this->applyTransformToPoint(
            $transformMatrix,
            $cx - $kx,
            $cy + $ry,
        );
        [$cpBottomLeft2X, $cpBottomLeft2Y] = $this->applyTransformToPoint(
            $transformMatrix,
            $cx - $rx,
            $cy + $ky,
        );
        [$cpLeftTop1X, $cpLeftTop1Y] = $this->applyTransformToPoint(
            $transformMatrix,
            $cx - $rx,
            $cy - $ky,
        );
        [$cpLeftTop2X, $cpLeftTop2Y] = $this->applyTransformToPoint(
            $transformMatrix,
            $cx - $kx,
            $cy - $ry,
        );

        $commands = [];
        $commands[] = sprintf('%F %F m', $topX - $minX, $maxY - $topY);
        $commands[] = sprintf(
            '%F %F %F %F %F %F c',
            $cpTopRight1X - $minX,
            $maxY - $cpTopRight1Y,
            $cpTopRight2X - $minX,
            $maxY - $cpTopRight2Y,
            $rightX - $minX,
            $maxY - $rightY,
        );
        $commands[] = sprintf(
            '%F %F %F %F %F %F c',
            $cpRightBottom1X - $minX,
            $maxY - $cpRightBottom1Y,
            $cpRightBottom2X - $minX,
            $maxY - $cpRightBottom2Y,
            $bottomX - $minX,
            $maxY - $bottomY,
        );
        $commands[] = sprintf(
            '%F %F %F %F %F %F c',
            $cpBottomLeft1X - $minX,
            $maxY - $cpBottomLeft1Y,
            $cpBottomLeft2X - $minX,
            $maxY - $cpBottomLeft2Y,
            $leftX - $minX,
            $maxY - $leftY,
        );
        $commands[] = sprintf(
            '%F %F %F %F %F %F c',
            $cpLeftTop1X - $minX,
            $maxY - $cpLeftTop1Y,
            $cpLeftTop2X - $minX,
            $maxY - $cpLeftTop2Y,
            $topX - $minX,
            $maxY - $topY,
        );
        $commands[] = 'h';

        return implode("\n", $commands);
    }

    /**
     * @param array{0:float,1:float,2:float,3:float,4:float,5:float} $transformMatrix
     */
    private function buildLineElementPath(DOMElement $element, float $minX, float $maxY, array $transformMatrix): string
    {
        $x1 = $this->extractNumericSvgLength($element->getAttribute('x1'));
        $y1 = $this->extractNumericSvgLength($element->getAttribute('y1'));
        $x2 = $this->extractNumericSvgLength($element->getAttribute('x2'));
        $y2 = $this->extractNumericSvgLength($element->getAttribute('y2'));

        [$x1, $y1] = $this->applyTransformToPoint($transformMatrix, $x1, $y1);
        [$x2, $y2] = $this->applyTransformToPoint($transformMatrix, $x2, $y2);

        return implode("\n", [
            sprintf('%F %F m', $x1 - $minX, $maxY - $y1),
            sprintf('%F %F l', $x2 - $minX, $maxY - $y2),
        ]);
    }

    /** @param array<string, string> $classFills */
    private function resolveFillColor(DOMElement $element, array $classFills): ?string
    {
        $inlineFill = $this->normalizeColor($element->getAttribute('fill'));
        if ($inlineFill === 'none') {
            return null;
        }

        if ($inlineFill !== null) {
            return $inlineFill;
        }

        $inlineStyle = $this->extractColorFromStyleAttribute($element->getAttribute('style'), 'fill');
        if ($inlineStyle === 'none') {
            return null;
        }

        if ($inlineStyle !== null) {
            return $inlineStyle;
        }

        $classes = preg_split('/\s+/', trim($element->getAttribute('class')));
        if (is_array($classes)) {
            foreach ($classes as $class) {
                if ($class !== '' && isset($classFills[$class])) {
                    $cv = $classFills[$class];
                    return $cv === 'none' ? null : $cv;
                }
            }
        }

        // Inherit fill from nearest ancestor <g> that declares one
        $ancestor = $element->parentNode;
        while ($ancestor instanceof DOMElement) {
            $ancestorFill = $this->normalizeColor($ancestor->getAttribute('fill'));
            if ($ancestorFill !== null) {
                return $ancestorFill === 'none' ? null : $ancestorFill;
            }

            $ancestorStyleFill = $this->extractColorFromStyleAttribute($ancestor->getAttribute('style'), 'fill');
            if ($ancestorStyleFill !== null) {
                return $ancestorStyleFill === 'none' ? null : $ancestorStyleFill;
            }

            $ancestor = $ancestor->parentNode;
        }

        return '#000000';
    }

    /**
     * @param array<string, string> $classStrokes
     */
    private function resolveStrokeColor(DOMElement $element, array $classStrokes): ?string
    {
        $inlineStroke = $this->normalizeColor($element->getAttribute('stroke'));
        if ($inlineStroke === 'none') {
            return null;
        }

        if ($inlineStroke !== null) {
            return $inlineStroke;
        }

        $inlineStyle = $this->extractColorFromStyleAttribute($element->getAttribute('style'), 'stroke');
        if ($inlineStyle === 'none') {
            return null;
        }

        if ($inlineStyle !== null) {
            return $inlineStyle;
        }

        $classes = preg_split('/\s+/', trim($element->getAttribute('class')));
        if (is_array($classes)) {
            foreach ($classes as $class) {
                if ($class !== '' && isset($classStrokes[$class])) {
                    return $classStrokes[$class] === 'none' ? null : $classStrokes[$class];
                }
            }
        }

        // Inherit stroke from nearest ancestor <g> that declares one
        $ancestor = $element->parentNode;
        while ($ancestor instanceof DOMElement) {
            $ancestorStroke = $this->normalizeColor($ancestor->getAttribute('stroke'));
            if ($ancestorStroke !== null) {
                return $ancestorStroke === 'none' ? null : $ancestorStroke;
            }

            $ancestor = $ancestor->parentNode;
        }

        return null;
    }

    private function resolveStrokeWidth(DOMElement $element): float
    {
        $attr = trim($element->getAttribute('stroke-width'));
        if ($attr !== '') {
            return max(0.0, $this->extractNumericSvgLength($attr));
        }

        $styleWidth = $this->extractValueFromStyleAttribute($element->getAttribute('style'), 'stroke-width');
        if ($styleWidth !== null) {
            return max(0.0, $this->extractNumericSvgLength($styleWidth));
        }

        return 1.0;
    }

    private function extractColorFromStyleAttribute(string $style, string $property): ?string
    {
        $value = $this->extractValueFromStyleAttribute($style, $property);
        if ($value === null) {
            return null;
        }

        return $this->normalizeColor($value);
    }

    private function extractValueFromStyleAttribute(string $style, string $property): ?string
    {
        if ($style === '') {
            return null;
        }

        $escaped = preg_quote($property, '/');
        if (preg_match('/(?:^|;)\s*' . $escaped . '\s*:\s*([^;]+)/i', $style, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }

    private function normalizeColor(string $color): ?string
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
            $red = max(0, min(255, (int) $matches[1]));
            $green = max(0, min(255, (int) $matches[2]));
            $blue = max(0, min(255, (int) $matches[3]));

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

    /**
     * @param array{0:float,1:float,2:float,3:float,4:float,5:float} $transformMatrix
     */
    private function convertPathData(
        string $pathData,
        float $minX,
        float $maxY,
        string $source,
        array $transformMatrix,
    ): string {
        preg_match_all(
            '/([A-Za-z])|([-+]?\d*\.?\d+(?:[eE][-+]?\d+)?)/',
            $pathData,
            $matches,
            PREG_SET_ORDER,
        );

        if ($matches === []) {
            throw new InvalidArgumentException(sprintf('Unsupported or empty SVG path data in "%s".', $source));
        }

        $tokens = [];
        foreach ($matches as $match) {
            $tokens[] = $match[1] !== '' ? $match[1] : $match[2];
        }

        $commands = [];
        $index = 0;
        $currentCommand = null;
        $currentX = 0.0;
        $currentY = 0.0;
        $lastCubicControlX = null;
        $lastCubicControlY = null;
        $lastQuadraticControlX = null;
        $lastQuadraticControlY = null;

        while ($index < count($tokens)) {
            $token = $tokens[$index];
            if (preg_match('/^[A-Za-z]$/', $token) === 1) {
                $currentCommand = $token;
                ++$index;
            }

            if ($currentCommand === null) {
                throw new InvalidArgumentException(sprintf('Invalid SVG path command sequence in "%s".', $source));
            }

            $isRelative = ctype_lower($currentCommand);
            $command = strtoupper($currentCommand);

            switch ($command) {
                case 'M':
                    $coordinates = $this->readPathNumbers($tokens, $index, 2, $source);
                    $currentX = $isRelative ? $currentX + $coordinates[0] : $coordinates[0];
                    $currentY = $isRelative ? $currentY + $coordinates[1] : $coordinates[1];
                    [$mX, $mY] = $this->applyTransformToPoint($transformMatrix, $currentX, $currentY);
                    $commands[] = sprintf('%F %F m', $mX - $minX, $maxY - $mY);
                    $lastCubicControlX = null;
                    $lastCubicControlY = null;

                    while ($index < count($tokens) && preg_match('/^[A-Za-z]$/', $tokens[$index]) !== 1) {
                        $coordinates = $this->readPathNumbers($tokens, $index, 2, $source);
                        $nextX = $isRelative ? $currentX + $coordinates[0] : $coordinates[0];
                        $nextY = $isRelative ? $currentY + $coordinates[1] : $coordinates[1];
                        $this->appendLineCommand(
                            $commands,
                            $transformMatrix,
                            $minX,
                            $maxY,
                            $currentX,
                            $currentY,
                            $nextX,
                            $nextY,
                        );
                    }
                        $lastCubicControlX     = null;
                        $lastCubicControlY     = null;
                        $lastQuadraticControlX = null;
                        $lastQuadraticControlY = null;
                    break;

                case 'L':
                    while ($index < count($tokens) && preg_match('/^[A-Za-z]$/', $tokens[$index]) !== 1) {
                        $coordinates = $this->readPathNumbers($tokens, $index, 2, $source);
                        $nextX = $isRelative ? $currentX + $coordinates[0] : $coordinates[0];
                        $nextY = $isRelative ? $currentY + $coordinates[1] : $coordinates[1];
                        $this->appendLineCommand(
                            $commands,
                            $transformMatrix,
                            $minX,
                            $maxY,
                            $currentX,
                            $currentY,
                            $nextX,
                            $nextY,
                        );
                        $lastCubicControlX = null;
                        $lastCubicControlY = null;
                    }
                    break;

                case 'H':
                    while ($index < count($tokens) && preg_match('/^[A-Za-z]$/', $tokens[$index]) !== 1) {
                        $coordinates = $this->readPathNumbers($tokens, $index, 1, $source);
                        $currentX = $isRelative ? $currentX + $coordinates[0] : $coordinates[0];
                        [$lX, $lY] = $this->applyTransformToPoint($transformMatrix, $currentX, $currentY);
                        $commands[] = sprintf('%F %F l', $lX - $minX, $maxY - $lY);
                        $lastCubicControlX = null;
                        $lastCubicControlY = null;
                    }
                    break;

                case 'V':
                    while ($index < count($tokens) && preg_match('/^[A-Za-z]$/', $tokens[$index]) !== 1) {
                        $coordinates = $this->readPathNumbers($tokens, $index, 1, $source);
                        $currentY = $isRelative ? $currentY + $coordinates[0] : $coordinates[0];
                        [$lX, $lY] = $this->applyTransformToPoint($transformMatrix, $currentX, $currentY);
                        $commands[] = sprintf('%F %F l', $lX - $minX, $maxY - $lY);
                        $lastCubicControlX = null;
                        $lastCubicControlY = null;
                    }
                    break;

                case 'C':
                    while ($index < count($tokens) && preg_match('/^[A-Za-z]$/', $tokens[$index]) !== 1) {
                        $coordinates = $this->readPathNumbers($tokens, $index, 6, $source);

                        $x1 = $isRelative ? $currentX + $coordinates[0] : $coordinates[0];
                        $y1 = $isRelative ? $currentY + $coordinates[1] : $coordinates[1];
                        $x2 = $isRelative ? $currentX + $coordinates[2] : $coordinates[2];
                        $y2 = $isRelative ? $currentY + $coordinates[3] : $coordinates[3];
                        $x = $isRelative ? $currentX + $coordinates[4] : $coordinates[4];
                        $y = $isRelative ? $currentY + $coordinates[5] : $coordinates[5];

                        $commands[] = $this->buildCubicCurveCommand(
                            $transformMatrix,
                            $minX,
                            $maxY,
                            $x1,
                            $y1,
                            $x2,
                            $y2,
                            $x,
                            $y,
                        );

                        $currentX = $x;
                        $currentY = $y;
                        $lastCubicControlX = $x2;
                        $lastCubicControlY = $y2;
                    }
                    break;

                case 'S':
                    while ($index < count($tokens) && preg_match('/^[A-Za-z]$/', $tokens[$index]) !== 1) {
                        $coordinates = $this->readPathNumbers($tokens, $index, 4, $source);

                        $x1 = $lastCubicControlX === null ? $currentX : (2 * $currentX) - $lastCubicControlX;
                        $y1 = $lastCubicControlY === null ? $currentY : (2 * $currentY) - $lastCubicControlY;
                        $x2 = $isRelative ? $currentX + $coordinates[0] : $coordinates[0];
                        $y2 = $isRelative ? $currentY + $coordinates[1] : $coordinates[1];
                        $x = $isRelative ? $currentX + $coordinates[2] : $coordinates[2];
                        $y = $isRelative ? $currentY + $coordinates[3] : $coordinates[3];

                        $commands[] = $this->buildCubicCurveCommand(
                            $transformMatrix,
                            $minX,
                            $maxY,
                            $x1,
                            $y1,
                            $x2,
                            $y2,
                            $x,
                            $y,
                        );

                        $currentX = $x;
                        $currentY = $y;
                        $lastCubicControlX = $x2;
                        $lastCubicControlY = $y2;
                    }
                    break;

                case 'Q':
                    // Quadratic Bézier → elevated to cubic:
                    // cp1 = current + 2/3*(cp − current), cp2 = end + 2/3*(cp − end)
                    while ($index < count($tokens) && preg_match('/^[A-Za-z]$/', $tokens[$index]) !== 1) {
                        $coordinates = $this->readPathNumbers($tokens, $index, 4, $source);

                        $qcpX = $isRelative ? $currentX + $coordinates[0] : $coordinates[0];
                        $qcpY = $isRelative ? $currentY + $coordinates[1] : $coordinates[1];
                        $x    = $isRelative ? $currentX + $coordinates[2] : $coordinates[2];
                        $y    = $isRelative ? $currentY + $coordinates[3] : $coordinates[3];

                        $x1 = $currentX + (2.0 / 3.0) * ($qcpX - $currentX);
                        $y1 = $currentY + (2.0 / 3.0) * ($qcpY - $currentY);
                        $x2 = $x + (2.0 / 3.0) * ($qcpX - $x);
                        $y2 = $y + (2.0 / 3.0) * ($qcpY - $y);

                        $commands[] = $this->buildCubicCurveCommand(
                            $transformMatrix,
                            $minX,
                            $maxY,
                            $x1,
                            $y1,
                            $x2,
                            $y2,
                            $x,
                            $y,
                        );

                        $currentX = $x;
                        $currentY = $y;
                        $lastCubicControlX = null;
                        $lastCubicControlY = null;
                        $lastQuadraticControlX = $qcpX;
                        $lastQuadraticControlY = $qcpY;
                    }
                    break;

                case 'T':
                    // Smooth quadratic: reflect previous quadratic control point
                    while ($index < count($tokens) && preg_match('/^[A-Za-z]$/', $tokens[$index]) !== 1) {
                        $coordinates = $this->readPathNumbers($tokens, $index, 2, $source);

                        $qcpX = $lastQuadraticControlX === null
                            ? $currentX
                            : (2.0 * $currentX) - $lastQuadraticControlX;
                        $qcpY = $lastQuadraticControlY === null
                            ? $currentY
                            : (2.0 * $currentY) - $lastQuadraticControlY;
                        $x    = $isRelative ? $currentX + $coordinates[0] : $coordinates[0];
                        $y    = $isRelative ? $currentY + $coordinates[1] : $coordinates[1];

                        $x1 = $currentX + (2.0 / 3.0) * ($qcpX - $currentX);
                        $y1 = $currentY + (2.0 / 3.0) * ($qcpY - $currentY);
                        $x2 = $x + (2.0 / 3.0) * ($qcpX - $x);
                        $y2 = $y + (2.0 / 3.0) * ($qcpY - $y);

                        $commands[] = $this->buildCubicCurveCommand(
                            $transformMatrix,
                            $minX,
                            $maxY,
                            $x1,
                            $y1,
                            $x2,
                            $y2,
                            $x,
                            $y,
                        );

                        $lastQuadraticControlX = $qcpX;
                        $lastQuadraticControlY = $qcpY;
                        $currentX = $x;
                        $currentY = $y;
                        $lastCubicControlX = null;
                        $lastCubicControlY = null;
                    }
                    break;

                case 'A':
                    // Elliptical arc: 7 params (rx ry x-rotation large-arc-flag sweep-flag x y)
                    while ($index < count($tokens) && preg_match('/^[A-Za-z]$/', $tokens[$index]) !== 1) {
                        $coordinates = $this->readPathNumbers($tokens, $index, 7, $source);

                        $rx       = abs($coordinates[0]);
                        $ry       = abs($coordinates[1]);
                        $rotation = $coordinates[2];
                        $largeArc = (int) $coordinates[3];
                        $sweep    = (int) $coordinates[4];
                        $x        = $isRelative ? $currentX + $coordinates[5] : $coordinates[5];
                        $y        = $isRelative ? $currentY + $coordinates[6] : $coordinates[6];

                        $curves = $this->arcToBezierCurves(
                            $currentX,
                            $currentY,
                            $rx,
                            $ry,
                            $rotation,
                            $largeArc,
                            $sweep,
                            $x,
                            $y,
                        );

                        foreach ($curves as $curve) {
                            [$cp1x, $cp1y] = $this->applyTransformToPoint($transformMatrix, $curve[0], $curve[1]);
                            [$cp2x, $cp2y] = $this->applyTransformToPoint($transformMatrix, $curve[2], $curve[3]);
                            [$ex, $ey] = $this->applyTransformToPoint($transformMatrix, $curve[4], $curve[5]);
                            $commands[] = sprintf(
                                '%F %F %F %F %F %F c',
                                $cp1x - $minX,
                                $maxY - $cp1y,
                                $cp2x - $minX,
                                $maxY - $cp2y,
                                $ex - $minX,
                                $maxY - $ey,
                            );
                        }

                        $currentX = $x;
                        $currentY = $y;
                        $lastCubicControlX = null;
                        $lastCubicControlY = null;
                    }
                    break;

                case 'Z':
                    $commands[] = 'h';
                    $lastCubicControlX = null;
                    $lastCubicControlY = null;
                    break;

                default:
                    throw new InvalidArgumentException(sprintf(
                        'SVG path command "%s" is not supported for source "%s".',
                        $currentCommand,
                        $source,
                    ));
            }
        }

        return implode("\n", $commands);
    }

    /**
     * Convert an SVG elliptical arc to one or more cubic Bézier curves.
     *
     * Follows W3C SVG Specification Appendix F.6 (endpoint → center parameterization).
     *
     * @return list<array{0:float,1:float,2:float,3:float,4:float,5:float}>
     */
    private function arcToBezierCurves(
        float $fromX,
        float $fromY,
        float $rx,
        float $ry,
        float $rotation,
        int $largeArc,
        int $sweep,
        float $toX,
        float $toY,
    ): array {
        if (abs($toX - $fromX) < 1e-10 && abs($toY - $fromY) < 1e-10) {
            return [];
        }

        if ($rx < 1e-10 || $ry < 1e-10) {
            return [[$toX, $toY, $toX, $toY, $toX, $toY]];
        }

        $th    = deg2rad($rotation);
        $cosTh = cos($th);
        $sinTh = sin($th);

        $dx2 = ($fromX - $toX) / 2.0;
        $dy2 = ($fromY - $toY) / 2.0;
        $px  =  $cosTh * $dx2 + $sinTh * $dy2;
        $py  = -$sinTh * $dx2 + $cosTh * $dy2;

        $rx2   = $rx * $rx;
        $ry2   = $ry * $ry;
        $px2   = $px * $px;
        $py2   = $py * $py;
        $scale = $px2 / $rx2 + $py2 / $ry2;
        if ($scale > 1.0) {
            $s   = sqrt($scale);
            $rx *= $s;
            $ry *= $s;
            $rx2 = $rx * $rx;
            $ry2 = $ry * $ry;
        }

        $num = max(0.0, $rx2 * $ry2 - $rx2 * $py2 - $ry2 * $px2);
        $den = $rx2 * $py2 + $ry2 * $px2;
        $sq  = $den > 1e-10 ? sqrt($num / $den) : 0.0;
        if ($largeArc === $sweep) {
            $sq = -$sq;
        }
        $cx1 =  $sq * $rx * $py / $ry;
        $cy1 = -$sq * $ry * $px / $rx;

        $midX = ($fromX + $toX) / 2.0;
        $midY = ($fromY + $toY) / 2.0;
        $cx   = $cosTh * $cx1 - $sinTh * $cy1 + $midX;
        $cy   = $sinTh * $cx1 + $cosTh * $cy1 + $midY;

        $ux = ($px - $cx1) / $rx;
        $uy = ($py - $cy1) / $ry;
        $vx = (-$px - $cx1) / $rx;
        $vy = (-$py - $cy1) / $ry;

        $startAngle = atan2($uy, $ux);
        $n          = sqrt(($ux * $ux + $uy * $uy) * ($vx * $vx + $vy * $vy));
        $cosDA      = $n > 1e-10 ? max(-1.0, min(1.0, ($ux * $vx + $uy * $vy) / $n)) : 0.0;
        $dAngle     = acos($cosDA);
        if ($ux * $vy - $uy * $vx < 0.0) {
            $dAngle = -$dAngle;
        }
        if ($sweep === 0 && $dAngle > 0.0) {
            $dAngle -= 2.0 * M_PI;
        } elseif ($sweep === 1 && $dAngle < 0.0) {
            $dAngle += 2.0 * M_PI;
        }

        $segments = max(1, (int) ceil(abs($dAngle) / (M_PI / 2.0)));
        $da       = $dAngle / $segments;
        $tanDA2   = tan($da / 2.0);
        $alpha    = abs($da) > 1e-10
            ? sin($da) * (sqrt(4.0 + 3.0 * $tanDA2 * $tanDA2) - 1.0) / 3.0
            : 0.0;

        $curves = [];
        $angle1 = $startAngle;
        $cos1   = cos($angle1);
        $sin1   = sin($angle1);
        $ex1    = $cx + $cosTh * $rx * $cos1 - $sinTh * $ry * $sin1;
        $ey1    = $cy + $sinTh * $rx * $cos1 + $cosTh * $ry * $sin1;

        for ($i = 0; $i < $segments; $i++) {
            $angle2 = $angle1 + $da;
            $cos2   = cos($angle2);
            $sin2   = sin($angle2);

            $ex2  = $cx + $cosTh * $rx * $cos2 - $sinTh * $ry * $sin2;
            $ey2  = $cy + $sinTh * $rx * $cos2 + $cosTh * $ry * $sin2;
            $txd1 = -$cosTh * $rx * $sin1 - $sinTh * $ry * $cos1;
            $tyd1 = -$sinTh * $rx * $sin1 + $cosTh * $ry * $cos1;
            $txd2 = -$cosTh * $rx * $sin2 - $sinTh * $ry * $cos2;
            $tyd2 = -$sinTh * $rx * $sin2 + $cosTh * $ry * $cos2;

            $curves[] = [
                $ex1 + $alpha * $txd1,
                $ey1 + $alpha * $tyd1,
                $ex2 - $alpha * $txd2,
                $ey2 - $alpha * $tyd2,
                $ex2,
                $ey2,
            ];

            $angle1 = $angle2;
            $cos1   = $cos2;
            $sin1   = $sin2;
            $ex1    = $ex2;
            $ey1    = $ey2;
        }

        return $curves;
    }

    /**
     * @param list<string> $tokens
     * @return list<float>
     */
    private function readPathNumbers(array $tokens, int &$index, int $count, string $source): array
    {
        $values = [];
        for ($cursor = 0; $cursor < $count; ++$cursor) {
            if (!isset($tokens[$index]) || preg_match('/^[A-Za-z]$/', $tokens[$index]) === 1) {
                throw new InvalidArgumentException(sprintf(
                    'Malformed SVG path data in "%s".',
                    $source,
                ));
            }

            $values[] = (float) $tokens[$index];
            ++$index;
        }

        return $values;
    }

    /**
     * @param array{0:float,1:float,2:float,3:float,4:float,5:float} $transformMatrix
     */
    private function buildCubicCurveCommand(
        array $transformMatrix,
        float $minX,
        float $maxY,
        float $x1,
        float $y1,
        float $x2,
        float $y2,
        float $x,
        float $y,
    ): string {
        [$tx1, $ty1] = $this->applyTransformToPoint($transformMatrix, $x1, $y1);
        [$tx2, $ty2] = $this->applyTransformToPoint($transformMatrix, $x2, $y2);
        [$tx, $ty] = $this->applyTransformToPoint($transformMatrix, $x, $y);

        return sprintf(
            '%F %F %F %F %F %F c',
            $tx1 - $minX,
            $maxY - $ty1,
            $tx2 - $minX,
            $maxY - $ty2,
            $tx - $minX,
            $maxY - $ty,
        );
    }

    /**
     * @param list<string> $commands
     * @param array{0:float,1:float,2:float,3:float,4:float,5:float} $transformMatrix
     */
    private function appendLineCommand(
        array &$commands,
        array $transformMatrix,
        float $minX,
        float $maxY,
        float &$currentX,
        float &$currentY,
        float $nextX,
        float $nextY,
    ): void {
        $currentX = $nextX;
        $currentY = $nextY;

        [$lX, $lY] = $this->applyTransformToPoint($transformMatrix, $currentX, $currentY);
        $commands[] = sprintf('%F %F l', $lX - $minX, $maxY - $lY);
    }

    /**
     * @return array{0:float,1:float,2:float,3:float,4:float,5:float}
     */
    private function resolveElementTransformMatrix(DOMElement $element): array
    {
        $matrix = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
        $ancestors = [];
        $cursor = $element;

        while ($cursor instanceof DOMElement) {
            $ancestors[] = $cursor;
            $cursor = $cursor->parentNode;
        }

        for ($index = count($ancestors) - 1; $index >= 0; --$index) {
            $transform = trim($ancestors[$index]->getAttribute('transform'));
            if ($transform === '') {
                continue;
            }

            $matrix = $this->multiplyMatrices($matrix, $this->parseTransformList($transform));
        }

        return $matrix;
    }

    /**
     * @return array{0:float,1:float,2:float,3:float,4:float,5:float}
     */
    private function parseTransformList(string $transform): array
    {
        $matrix = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];

        if (
            preg_match_all(
                '/(matrix|translate|scale|rotate|skewX|skewY)\s*\(([^)]*)\)/i',
                $transform,
                $matches,
                PREG_SET_ORDER,
            ) < 1
        ) {
            return $matrix;
        }

        foreach ($matches as $match) {
            $op = strtolower($match[1]);
            $args = preg_split('/[\s,]+/', trim($match[2]));
            if (!is_array($args)) {
                continue;
            }

            $values = [];
            foreach ($args as $arg) {
                if ($arg === '') {
                    continue;
                }

                $values[] = (float) $arg;
            }

            $operationMatrix = match ($op) {
                'matrix' => count($values) >= 6
                    ? [$values[0], $values[1], $values[2], $values[3], $values[4], $values[5]]
                    : [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
                'translate' => [1.0, 0.0, 0.0, 1.0, $values[0] ?? 0.0, $values[1] ?? 0.0],
                'scale' => [$values[0] ?? 1.0, 0.0, 0.0, $values[1] ?? ($values[0] ?? 1.0), 0.0, 0.0],
                'rotate' => $this->buildRotateMatrix($values),
                'skewx' => [1.0, 0.0, tan(deg2rad($values[0] ?? 0.0)), 1.0, 0.0, 0.0],
                'skewy' => [1.0, tan(deg2rad($values[0] ?? 0.0)), 0.0, 1.0, 0.0, 0.0],
                default => [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
            };

            $matrix = $this->multiplyMatrices($matrix, $operationMatrix);
        }

        return $matrix;
    }

    /**
     * @param list<float> $values
     * @return array{0:float,1:float,2:float,3:float,4:float,5:float}
     */
    private function buildRotateMatrix(array $values): array
    {
        $angle = deg2rad($values[0] ?? 0.0);
        $cos = cos($angle);
        $sin = sin($angle);

        $rotation = [$cos, $sin, -$sin, $cos, 0.0, 0.0];

        if (count($values) < 3) {
            return $rotation;
        }

        $cx = $values[1];
        $cy = $values[2];

        return $this->multiplyMatrices(
            $this->multiplyMatrices([1.0, 0.0, 0.0, 1.0, $cx, $cy], $rotation),
            [1.0, 0.0, 0.0, 1.0, -$cx, -$cy],
        );
    }

    /**
     * Matrix multiplication for SVG affine matrices.
     *
     * @param array{0:float,1:float,2:float,3:float,4:float,5:float} $left
     * @param array{0:float,1:float,2:float,3:float,4:float,5:float} $right
     * @return array{0:float,1:float,2:float,3:float,4:float,5:float}
     */
    private function multiplyMatrices(array $left, array $right): array
    {
        return [
            $left[0] * $right[0] + $left[2] * $right[1],
            $left[1] * $right[0] + $left[3] * $right[1],
            $left[0] * $right[2] + $left[2] * $right[3],
            $left[1] * $right[2] + $left[3] * $right[3],
            $left[0] * $right[4] + $left[2] * $right[5] + $left[4],
            $left[1] * $right[4] + $left[3] * $right[5] + $left[5],
        ];
    }

    /**
     * @param array{0:float,1:float,2:float,3:float,4:float,5:float} $matrix
     * @return array{0:float,1:float}
     */
    private function applyTransformToPoint(array $matrix, float $x, float $y): array
    {
        return [
            $matrix[0] * $x + $matrix[2] * $y + $matrix[4],
            $matrix[1] * $x + $matrix[3] * $y + $matrix[5],
        ];
    }
}
