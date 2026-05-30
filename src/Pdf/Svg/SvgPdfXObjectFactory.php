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
        $rawCount = count($raw);
        if ($rawCount < 4 || $rawCount % 2 !== 0) {
            return null;
        }

        $commands = [];
        $x = (float) $raw[0];
        $y = (float) $raw[1];
        [$x, $y] = $this->applyTransformToPoint($transformMatrix, $x, $y);
        $commands[] = sprintf('%F %F m', $x - $minX, $maxY - $y);

        for ($index = 2; $index < $rawCount; $index += 2) {
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
        $rawCount = count($raw);
        if ($rawCount < 4 || $rawCount % 2 !== 0) {
            return null;
        }

        $commands = [];
        [$firstX, $firstY] = $this->applyTransformToPoint(
            $transformMatrix,
            (float) $raw[0],
            (float) $raw[1],
        );
        $commands[] = sprintf('%F %F m', $firstX - $minX, $maxY - $firstY);

        for ($index = 2; $index < $rawCount; $index += 2) {
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
    /**
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
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

    /**
     * @param array<string, string> $classFills
     */
    private function resolveFillColor(DOMElement $element, array $classFills): ?string
    {
        return $this->resolveColorAttribute($element, 'fill', $classFills, '#000000');
    }

    /**
     * @param array<string, string> $classStrokes
     */
    private function resolveStrokeColor(DOMElement $element, array $classStrokes): ?string
    {
        return $this->resolveColorAttribute($element, 'stroke', $classStrokes, null);
    }

    /**
     * @param array<string, string> $classColors
     */
    private function resolveColorAttribute(
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
        $classes = preg_split('/\s+/', trim($element->getAttribute('class')));
        if (is_array($classes)) {
            foreach ($classes as $class) {
                if ($class !== '' && isset($classColors[$class])) {
                    $classColor = $classColors[$class];
                    return $classColor === 'none' ? null : $classColor;
                }
            }
        }

        // Check ancestors
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

        $state = new PathParsingState();
        $context = new PathCommandContext($transformMatrix, $minX, $maxY, $source);
        $tokenCount = count($tokens);
        $index = 0;
        $currentCommand = null;

        while ($index < $tokenCount) {
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

            $this->handlePathCommand(
                $command,
                $isRelative,
                $tokens,
                $index,
                $tokenCount,
                $state,
                $context,
            );
        }

        return implode("\n", $state->commands);
    }

    /**
     * Route path command to appropriate handler.
     *
     * @param list<string> $tokens
     */
    private function handlePathCommand(
        string $command,
        bool $isRelative,
        array $tokens,
        int &$index,
        int $tokenCount,
        PathParsingState $state,
        PathCommandContext $context,
    ): void {
        match ($command) {
            'M' => $this->handleMoveCommand($tokens, $index, $tokenCount, $isRelative, $state, $context),
            'L' => $this->handleLineCommand($tokens, $index, $tokenCount, $isRelative, $state, $context),
            'H' => $this->handleHorizontalCommand($tokens, $index, $tokenCount, $isRelative, $state, $context),
            'V' => $this->handleVerticalCommand($tokens, $index, $tokenCount, $isRelative, $state, $context),
            'C' => $this->handleCubicCommand($tokens, $index, $tokenCount, $isRelative, $state, $context),
            'S' => $this->handleSmoothCubicCommand($tokens, $index, $tokenCount, $isRelative, $state, $context),
            'Q' => $this->handleQuadraticCommand($tokens, $index, $tokenCount, $isRelative, $state, $context),
            'T' => $this->handleSmoothQuadraticCommand($tokens, $index, $tokenCount, $isRelative, $state, $context),
            'A' => $this->handleArcCommand($tokens, $index, $tokenCount, $isRelative, $state, $context),
            'Z' => $this->handleClosePathCommand($state),
            default => throw new InvalidArgumentException(sprintf(
                'SVG path command "%s" is not supported for source "%s".',
                $command,
                $context->source,
            )),
        };
    }

    /**
     * @param list<string> $tokens
     */
    private function handleMoveCommand(
        array $tokens,
        int &$index,
        int $tokenCount,
        bool $isRelative,
        PathParsingState $state,
        PathCommandContext $context,
    ): void {
        $coordinates = $this->readPathNumbers($tokens, $index, 2, $context->source);
        $state->currentX = $isRelative ? $state->currentX + $coordinates[0] : $coordinates[0];
        $state->currentY = $isRelative ? $state->currentY + $coordinates[1] : $coordinates[1];
        [$mX, $mY] = $this->applyTransformToPoint($context->transformMatrix, $state->currentX, $state->currentY);
        $state->commands[] = sprintf('%F %F m', $mX - $context->minX, $context->maxY - $mY);
        $state->lastCubicControlX = null;
        $state->lastCubicControlY = null;

        while ($index < $tokenCount && preg_match('/^[A-Za-z]$/', $tokens[$index]) !== 1) {
            $coordinates = $this->readPathNumbers($tokens, $index, 2, $context->source);
            $nextX = $isRelative ? $state->currentX + $coordinates[0] : $coordinates[0];
            $nextY = $isRelative ? $state->currentY + $coordinates[1] : $coordinates[1];
            $this->appendLineToState($state, $context, $nextX, $nextY);
        }
        $state->lastQuadraticControlX = null;
        $state->lastQuadraticControlY = null;
    }

    /**
     * @param list<string> $tokens
     */
    private function handleLineCommand(
        array $tokens,
        int &$index,
        int $tokenCount,
        bool $isRelative,
        PathParsingState $state,
        PathCommandContext $context,
    ): void {
        while ($index < $tokenCount && preg_match('/^[A-Za-z]$/', $tokens[$index]) !== 1) {
            $coordinates = $this->readPathNumbers($tokens, $index, 2, $context->source);
            $nextX = $isRelative ? $state->currentX + $coordinates[0] : $coordinates[0];
            $nextY = $isRelative ? $state->currentY + $coordinates[1] : $coordinates[1];
            $this->appendLineToState($state, $context, $nextX, $nextY);
        }
    }

    /**
     * @param list<string> $tokens
     */
    private function handleHorizontalCommand(
        array $tokens,
        int &$index,
        int $tokenCount,
        bool $isRelative,
        PathParsingState $state,
        PathCommandContext $context,
    ): void {
        while ($index < $tokenCount && preg_match('/^[A-Za-z]$/', $tokens[$index]) !== 1) {
            $coordinates = $this->readPathNumbers($tokens, $index, 1, $context->source);
            $state->currentX = $isRelative ? $state->currentX + $coordinates[0] : $coordinates[0];
            [$lX, $lY] = $this->applyTransformToPoint($context->transformMatrix, $state->currentX, $state->currentY);
            $state->commands[] = sprintf('%F %F l', $lX - $context->minX, $context->maxY - $lY);
            $state->lastCubicControlX = null;
            $state->lastCubicControlY = null;
        }
    }

    /**
     * @param list<string> $tokens
     */
    private function handleVerticalCommand(
        array $tokens,
        int &$index,
        int $tokenCount,
        bool $isRelative,
        PathParsingState $state,
        PathCommandContext $context,
    ): void {
        while ($index < $tokenCount && preg_match('/^[A-Za-z]$/', $tokens[$index]) !== 1) {
            $coordinates = $this->readPathNumbers($tokens, $index, 1, $context->source);
            $state->currentY = $isRelative ? $state->currentY + $coordinates[0] : $coordinates[0];
            [$lX, $lY] = $this->applyTransformToPoint($context->transformMatrix, $state->currentX, $state->currentY);
            $state->commands[] = sprintf('%F %F l', $lX - $context->minX, $context->maxY - $lY);
            $state->lastCubicControlX = null;
            $state->lastCubicControlY = null;
        }
    }

    /**
     * @param list<string> $tokens
     */
    private function handleCubicCommand(
        array $tokens,
        int &$index,
        int $tokenCount,
        bool $isRelative,
        PathParsingState $state,
        PathCommandContext $context,
    ): void {
        while ($index < $tokenCount && preg_match('/^[A-Za-z]$/', $tokens[$index]) !== 1) {
            $coordinates = $this->readPathNumbers($tokens, $index, 6, $context->source);
            $x1 = $isRelative ? $state->currentX + $coordinates[0] : $coordinates[0];
            $y1 = $isRelative ? $state->currentY + $coordinates[1] : $coordinates[1];
            $x2 = $isRelative ? $state->currentX + $coordinates[2] : $coordinates[2];
            $y2 = $isRelative ? $state->currentY + $coordinates[3] : $coordinates[3];
            $x = $isRelative ? $state->currentX + $coordinates[4] : $coordinates[4];
            $y = $isRelative ? $state->currentY + $coordinates[5] : $coordinates[5];

            $state->commands[] = $this->buildCubicCurveCommand($context->transformMatrix, $context->minX, $context->maxY, $x1, $y1, $x2, $y2, $x, $y);
            $state->currentX = $x;
            $state->currentY = $y;
            $state->lastCubicControlX = $x2;
            $state->lastCubicControlY = $y2;
        }
    }

    /**
     * @param list<string> $tokens
     */
    private function handleSmoothCubicCommand(
        array $tokens,
        int &$index,
        int $tokenCount,
        bool $isRelative,
        PathParsingState $state,
        PathCommandContext $context,
    ): void {
        while ($index < $tokenCount && preg_match('/^[A-Za-z]$/', $tokens[$index]) !== 1) {
            $coordinates = $this->readPathNumbers($tokens, $index, 4, $context->source);
            $x1 = $state->lastCubicControlX === null ? $state->currentX : (2 * $state->currentX) - $state->lastCubicControlX;
            $y1 = $state->lastCubicControlY === null ? $state->currentY : (2 * $state->currentY) - $state->lastCubicControlY;
            $x2 = $isRelative ? $state->currentX + $coordinates[0] : $coordinates[0];
            $y2 = $isRelative ? $state->currentY + $coordinates[1] : $coordinates[1];
            $x = $isRelative ? $state->currentX + $coordinates[2] : $coordinates[2];
            $y = $isRelative ? $state->currentY + $coordinates[3] : $coordinates[3];

            $state->commands[] = $this->buildCubicCurveCommand($context->transformMatrix, $context->minX, $context->maxY, $x1, $y1, $x2, $y2, $x, $y);
            $state->currentX = $x;
            $state->currentY = $y;
            $state->lastCubicControlX = $x2;
            $state->lastCubicControlY = $y2;
        }
    }

    /**
     * @param list<string> $tokens
     */
    private function handleQuadraticCommand(
        array $tokens,
        int &$index,
        int $tokenCount,
        bool $isRelative,
        PathParsingState $state,
        PathCommandContext $context,
    ): void {
        while ($index < $tokenCount && preg_match('/^[A-Za-z]$/', $tokens[$index]) !== 1) {
            $coordinates = $this->readPathNumbers($tokens, $index, 4, $context->source);
            $qcpX = $isRelative ? $state->currentX + $coordinates[0] : $coordinates[0];
            $qcpY = $isRelative ? $state->currentY + $coordinates[1] : $coordinates[1];
            $x    = $isRelative ? $state->currentX + $coordinates[2] : $coordinates[2];
            $y    = $isRelative ? $state->currentY + $coordinates[3] : $coordinates[3];

            $x1 = $state->currentX + (2.0 / 3.0) * ($qcpX - $state->currentX);
            $y1 = $state->currentY + (2.0 / 3.0) * ($qcpY - $state->currentY);
            $x2 = $x + (2.0 / 3.0) * ($qcpX - $x);
            $y2 = $y + (2.0 / 3.0) * ($qcpY - $y);

            $state->commands[] = $this->buildCubicCurveCommand($context->transformMatrix, $context->minX, $context->maxY, $x1, $y1, $x2, $y2, $x, $y);
            $state->currentX = $x;
            $state->currentY = $y;
            $state->lastCubicControlX = null;
            $state->lastCubicControlY = null;
            $state->lastQuadraticControlX = $qcpX;
            $state->lastQuadraticControlY = $qcpY;
        }
    }

    /**
     * @param list<string> $tokens
     */
    private function handleSmoothQuadraticCommand(
        array $tokens,
        int &$index,
        int $tokenCount,
        bool $isRelative,
        PathParsingState $state,
        PathCommandContext $context,
    ): void {
        while ($index < $tokenCount && preg_match('/^[A-Za-z]$/', $tokens[$index]) !== 1) {
            $coordinates = $this->readPathNumbers($tokens, $index, 2, $context->source);
            $qcpX = $state->lastQuadraticControlX === null
                ? $state->currentX
                : (2.0 * $state->currentX) - $state->lastQuadraticControlX;
            $qcpY = $state->lastQuadraticControlY === null
                ? $state->currentY
                : (2.0 * $state->currentY) - $state->lastQuadraticControlY;
            $x = $isRelative ? $state->currentX + $coordinates[0] : $coordinates[0];
            $y = $isRelative ? $state->currentY + $coordinates[1] : $coordinates[1];

            $x1 = $state->currentX + (2.0 / 3.0) * ($qcpX - $state->currentX);
            $y1 = $state->currentY + (2.0 / 3.0) * ($qcpY - $state->currentY);
            $x2 = $x + (2.0 / 3.0) * ($qcpX - $x);
            $y2 = $y + (2.0 / 3.0) * ($qcpY - $y);

            $state->commands[] = $this->buildCubicCurveCommand($context->transformMatrix, $context->minX, $context->maxY, $x1, $y1, $x2, $y2, $x, $y);
            $state->lastQuadraticControlX = $qcpX;
            $state->lastQuadraticControlY = $qcpY;
            $state->currentX = $x;
            $state->currentY = $y;
            $state->lastCubicControlX = null;
            $state->lastCubicControlY = null;
        }
    }

    /**
     * @param list<string> $tokens
     */
    private function handleArcCommand(
        array $tokens,
        int &$index,
        int $tokenCount,
        bool $isRelative,
        PathParsingState $state,
        PathCommandContext $context,
    ): void {
        while ($index < $tokenCount && preg_match('/^[A-Za-z]$/', $tokens[$index]) !== 1) {
            $coordinates = $this->readPathNumbers($tokens, $index, 7, $context->source);
            $rx       = abs($coordinates[0]);
            $ry       = abs($coordinates[1]);
            $rotation = $coordinates[2];
            $largeArc = (int) $coordinates[3];
            $sweep    = (int) $coordinates[4];
            $x        = $isRelative ? $state->currentX + $coordinates[5] : $coordinates[5];
            $y        = $isRelative ? $state->currentY + $coordinates[6] : $coordinates[6];

            $curves = $this->arcToBezierCurves($state->currentX, $state->currentY, $rx, $ry, $rotation, $largeArc, $sweep, $x, $y);

            foreach ($curves as $curve) {
                [$cp1x, $cp1y] = $this->applyTransformToPoint($context->transformMatrix, $curve[0], $curve[1]);
                [$cp2x, $cp2y] = $this->applyTransformToPoint($context->transformMatrix, $curve[2], $curve[3]);
                [$ex, $ey] = $this->applyTransformToPoint($context->transformMatrix, $curve[4], $curve[5]);
                $state->commands[] = sprintf(
                    '%F %F %F %F %F %F c',
                    $cp1x - $context->minX,
                    $context->maxY - $cp1y,
                    $cp2x - $context->minX,
                    $context->maxY - $cp2y,
                    $ex - $context->minX,
                    $context->maxY - $ey,
                );
            }

            $state->currentX = $x;
            $state->currentY = $y;
            $state->lastCubicControlX = null;
            $state->lastCubicControlY = null;
        }
    }

    private function handleClosePathCommand(PathParsingState $state): void
    {
        $state->commands[] = 'h';
        $state->lastCubicControlX = null;
        $state->lastCubicControlY = null;
    }

    private function appendLineToState(
        PathParsingState $state,
        PathCommandContext $context,
        float $toX,
        float $toY,
    ): void {
        $state->currentX = $toX;
        $state->currentY = $toY;
        [$lX, $lY] = $this->applyTransformToPoint($context->transformMatrix, $toX, $toY);
        $state->commands[] = sprintf('%F %F l', $lX - $context->minX, $context->maxY - $lY);
        $state->lastCubicControlX = null;
        $state->lastCubicControlY = null;
    }

    /**
     * Convert an SVG elliptical arc to one or more cubic Bézier curves.
     *
     * Follows W3C SVG Specification Appendix F.6 (endpoint → center parameterization).
     *
     * @return list<array{0:float,1:float,2:float,3:float,4:float,5:float}>
     */
    /**
     * @SuppressWarnings(PHPMD.CyclomaticComplexity,PHPMD.NPathComplexity,PHPMD.ExcessiveMethodLength)
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

        // Step 1: Normalize radii and compute half-distances
        [$rx, $ry] = $this->normalizeArcRadii(
            $fromX,
            $fromY,
            $toX,
            $toY,
            $rx,
            $ry,
            $cosTh,
            $sinTh,
        );

        // Step 2: Calculate center point
        [$cx, $cy] = $this->calculateArcCenter(
            $fromX,
            $fromY,
            $toX,
            $toY,
            $rx,
            $ry,
            $cosTh,
            $sinTh,
            $largeArc,
            $sweep,
        );

        // Step 3: Calculate angles and deltas
        [$startAngle, $dAngle] = $this->calculateArcAngles(
            $fromX,
            $fromY,
            $toX,
            $toY,
            $cx,
            $cy,
            $rx,
            $ry,
            $cosTh,
            $sinTh,
            $largeArc,
            $sweep,
        );

        // Step 4: Generate cubic Bézier curves
        return $this->generateArcCurves($cx, $cy, $rx, $ry, $cosTh, $sinTh, $startAngle, $dAngle);
    }

    private function normalizeArcRadii(
        float $fromX,
        float $fromY,
        float $toX,
        float $toY,
        float $rx,
        float $ry,
        float $cosTh,
        float $sinTh,
    ): array {
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
        }

        return [$rx, $ry];
    }

    private function calculateArcCenter(
        float $fromX,
        float $fromY,
        float $toX,
        float $toY,
        float $rx,
        float $ry,
        float $cosTh,
        float $sinTh,
        int $largeArc,
        int $sweep,
    ): array {
        $dx2 = ($fromX - $toX) / 2.0;
        $dy2 = ($fromY - $toY) / 2.0;
        $px  =  $cosTh * $dx2 + $sinTh * $dy2;
        $py  = -$sinTh * $dx2 + $cosTh * $dy2;

        $rx2   = $rx * $rx;
        $ry2   = $ry * $ry;
        $px2   = $px * $px;
        $py2   = $py * $py;
        $num   = max(0.0, $rx2 * $ry2 - $rx2 * $py2 - $ry2 * $px2);
        $den   = $rx2 * $py2 + $ry2 * $px2;
        $sq    = $den > 1e-10 ? sqrt($num / $den) : 0.0;
        if ($largeArc === $sweep) {
            $sq = -$sq;
        }

        $cx1 =  $sq * $rx * $py / $ry;
        $cy1 = -$sq * $ry * $px / $rx;

        $midX = ($fromX + $toX) / 2.0;
        $midY = ($fromY + $toY) / 2.0;
        $cx   = $cosTh * $cx1 - $sinTh * $cy1 + $midX;
        $cy   = $sinTh * $cx1 + $cosTh * $cy1 + $midY;

        return [$cx, $cy];
    }

    private function calculateArcAngles(
        float $fromX,
        float $fromY,
        float $toX,
        float $toY,
        float $cx,
        float $cy,
        float $rx,
        float $ry,
        float $cosTh,
        float $sinTh,
        int $largeArc,
        int $sweep,
    ): array {
        $dx2 = ($fromX - $toX) / 2.0;
        $dy2 = ($fromY - $toY) / 2.0;
        $px  =  $cosTh * $dx2 + $sinTh * $dy2;
        $py  = -$sinTh * $dx2 + $cosTh * $dy2;

        $ux = ($px - 0.0) / $rx;
        $uy = ($py - 0.0) / $ry;
        $vx = (-$px - 0.0) / $rx;
        $vy = (-$py - 0.0) / $ry;

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

        return [$startAngle, $dAngle];
    }

    private function generateArcCurves(
        float $cx,
        float $cy,
        float $rx,
        float $ry,
        float $cosTh,
        float $sinTh,
        float $startAngle,
        float $dAngle,
    ): array {
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
