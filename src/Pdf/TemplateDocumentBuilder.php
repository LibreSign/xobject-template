<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf;

use Closure;
use LibreSign\XObjectTemplate\Dto\CompileRequest;
use LibreSign\XObjectTemplate\Dto\CompileResult;
use LibreSign\XObjectTemplate\Layout\LayoutDecoration;
use LibreSign\XObjectTemplate\Layout\LayoutImage;
use LibreSign\XObjectTemplate\Layout\LayoutLine;
use LibreSign\XObjectTemplate\Layout\LayoutResult;

final readonly class TemplateDocumentBuilder
{
    private const DEFAULT_FONT_RESOURCES = [
        'F1' => [
            'Type' => '/Font',
            'Subtype' => '/Type1',
            'BaseFont' => '/Helvetica',
        ],
        'F2' => [
            'Type' => '/Font',
            'Subtype' => '/Type1',
            'BaseFont' => '/Helvetica-Bold',
        ],
        'F3' => [
            'Type' => '/Font',
            'Subtype' => '/Type1',
            'BaseFont' => '/Times-Roman',
        ],
        'F4' => [
            'Type' => '/Font',
            'Subtype' => '/Type1',
            'BaseFont' => '/Times-Bold',
        ],
        'F5' => [
            'Type' => '/Font',
            'Subtype' => '/Type1',
            'BaseFont' => '/Courier',
        ],
        'F6' => [
            'Type' => '/Font',
            'Subtype' => '/Type1',
            'BaseFont' => '/Courier-Bold',
        ],
    ];

    /** @var Closure():int */
    private Closure $clock;

    /**
     * @param array<string, array<string, mixed>> $fontResources
     * @param ?Closure():int $clock
     */
    public function __construct(
        private PdfEscaper $pdfEscaper = new PdfEscaper(),
        private ColorParser $colorParser = new ColorParser(),
        private array $fontResources = self::DEFAULT_FONT_RESOURCES,
        ?Closure $clock = null,
        /** @var list<TrueTypeFont> */
        private array $unicodeFonts = [],
        private ComplexTextShaperInterface $complexShaper = new HarfBuzzComplexTextShaper(),
    ) {
        $this->clock = $clock ?? static fn (): int => hrtime(true);
    }

    public function build(
        CompileRequest $request,
        LayoutResult $layout,
        int $startedAtNs,
        int $nodeCount = 0,
    ): CompileResult {
        $unicode = new UnicodeTextResources($layout->lines, $this->unicodeFonts, $this->complexShaper);
        return new CompileResult(
            contentStream: $this->buildContentStream($layout, $unicode),
            resources: $this->buildResources($layout, $unicode),
            bbox: [0.0, 0.0, $request->width, $request->height],
            metadata: $this->buildMetadata($layout, $startedAtNs, $nodeCount),
        );
    }

    public function buildContentStream(LayoutResult $layout, ?UnicodeTextResources $unicode = null): string
    {
        $stream = ['q'];

        foreach ($layout->decorations as $decoration) {
            $stream[] = $this->buildDecorationCommand($decoration);
        }

        foreach ($layout->images as $image) {
            $stream[] = $this->buildImageCommand($image);
        }

        $unicode ??= new UnicodeTextResources($layout->lines, $this->unicodeFonts, $this->complexShaper);
        $this->appendTextCommands($stream, $layout->lines, $unicode);

        $stream[] = 'Q';

        return implode("\n", $stream);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildResources(LayoutResult $layout, ?UnicodeTextResources $unicode = null): array
    {
        $unicode ??= new UnicodeTextResources($layout->lines, $this->unicodeFonts, $this->complexShaper);
        $resources = [
            'Font' => array_merge($this->fontResources, $unicode->fonts()),
            'XObject' => [],
        ];

        foreach ($layout->images as $image) {
            $resources['XObject'][$image->alias] = [
                'Type' => '/XObject',
                'Subtype' => '/Image',
                'Source' => $image->source,
                'Width' => $image->width,
                'Height' => $image->height,
            ];
        }

        return $resources;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildMetadata(LayoutResult $layout, int $startedAtNs, int $nodeCount = 0): array
    {
        return [
            'render_ms' => round((($this->clock)() - $startedAtNs) / 1_000_000, 3),
            'line_count' => count($layout->lines),
            'image_count' => count($layout->images),
            'node_count' => $nodeCount,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $fontResources
     */
    public function withFontResources(array $fontResources): self
    {
        return new self(
            $this->pdfEscaper,
            $this->colorParser,
            $fontResources,
            $this->clock,
            $this->unicodeFonts,
            $this->complexShaper,
        );
    }

    /**
     * Configure embeddable TrueType fonts (in fallback order). Each grapheme
     * must be covered by one configured font.
     *
     * @param list<string> $paths
     */
    public function withUnicodeFontFiles(array $paths): self
    {
        $fonts = array_map(static fn (string $path): TrueTypeFont => new TrueTypeFont($path), $paths);
        return new self(
            $this->pdfEscaper,
            $this->colorParser,
            $this->fontResources,
            $this->clock,
            $fonts,
            $this->complexShaper,
        );
    }

    /** @return list<TrueTypeFont> */
    public function unicodeFonts(): array
    {
        return $this->unicodeFonts;
    }

    public function complexShaper(): ComplexTextShaperInterface
    {
        return $this->complexShaper;
    }

    private function buildImageCommand(LayoutImage $image): string
    {
        if ($image->clipBox === null) {
            return sprintf(
                'q %F 0 0 %F %F %F cm /%s Do Q',
                $image->width,
                $image->height,
                $image->x,
                $image->y,
                $image->alias,
            );
        }

        return implode("\n", [
            'q',
            $this->buildClipCommand($image->clipBox),
            sprintf(
                '%F 0 0 %F %F %F cm /%s Do',
                $image->width,
                $image->height,
                $image->x,
                $image->y,
                $image->alias,
            ),
            'Q',
        ]);
    }

    /**
     * @param list<LayoutLine> $lines
     * @param list<string> $stream
     */
    private function appendTextCommands(array &$stream, array $lines, UnicodeTextResources $unicode): void
    {
        if ($lines === []) {
            return;
        }

        if ($this->hasClippedText($lines)) {
            foreach ($lines as $index => $line) {
                $stream[] = $this->buildTextCommand($line, $index, $unicode);
            }

            return;
        }

        foreach ($this->buildGroupedTextCommands($lines, $unicode) as $command) {
            $stream[] = $command;
        }
    }

    /**
     * @param list<LayoutLine> $lines
     */
    private function hasClippedText(array $lines): bool
    {
        foreach ($lines as $line) {
            if ($line->clipBox !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<LayoutLine> $lines
     * @return list<string>
     */
    private function buildGroupedTextCommands(array $lines, UnicodeTextResources $unicode): array
    {
        $commands = ['BT'];
        $currentWordSpacing = 0.0;

        foreach ($lines as $index => $line) {
            $commands[] = sprintf('/%s %F Tf', $unicode->aliasFor($index, $line->fontAlias), $line->fontSize);
            $commands[] = $this->colorParser->toPdfRgb($line->rgbColor);
            if ($line->wordSpacing !== $currentWordSpacing) {
                $commands[] = sprintf('%F Tw', $line->wordSpacing);
                $currentWordSpacing = $line->wordSpacing;
            }

            $commands[] = sprintf('1 0 0 1 %F %F Tm', $line->x, $line->y);
            $commands[] = $unicode->textOperator($index, $line->text, $this->pdfEscaper);
        }

        $commands[] = 'ET';

        return $commands;
    }

    private function buildTextCommand(LayoutLine $line, int $index, UnicodeTextResources $unicode): string
    {
        $commands = ['q'];
        if ($line->clipBox !== null) {
            $commands[] = $this->buildClipCommand($line->clipBox);
        }

        $commands[] = 'BT';
        $commands[] = sprintf('/%s %F Tf', $unicode->aliasFor($index, $line->fontAlias), $line->fontSize);
        $commands[] = $this->colorParser->toPdfRgb($line->rgbColor);
        if ($line->wordSpacing !== 0.0) {
            $commands[] = sprintf('%F Tw', $line->wordSpacing);
        }

        $commands[] = sprintf('1 0 0 1 %F %F Tm', $line->x, $line->y);
        $commands[] = $unicode->textOperator($index, $line->text, $this->pdfEscaper);
        $commands[] = 'ET';
        $commands[] = 'Q';

        return implode("\n", $commands);
    }

    private function buildDecorationCommand(LayoutDecoration $decoration): string
    {
        $hasFill = $decoration->fillColor !== null && $decoration->fillColor !== '';
        $hasStroke = $decoration->strokeColor !== null
            && $decoration->strokeColor !== ''
            && $decoration->strokeWidth > 0.0;

        if (!$hasFill && !$hasStroke) {
            return 'q\nQ';
        }

        $commands = ['q'];
        if ($hasFill) {
            $commands[] = $this->colorParser->toPdfRgb($decoration->fillColor);
        }

        if ($hasStroke) {
            $commands[] = $this->colorParser->toPdfStrokeRgb($decoration->strokeColor);
            $commands[] = sprintf('%F w', $decoration->strokeWidth);
        }

        $commands[] = $this->buildDecorationPath($decoration);
        $commands[] = match (true) {
            $hasFill && $hasStroke => 'B',
            $hasFill => 'f',
            default => 'S',
        };
        $commands[] = 'Q';

        return implode("\n", $commands);
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $clipBox
     */
    private function buildClipCommand(array $clipBox): string
    {
        return sprintf('%F %F %F %F re W n', $clipBox['x'], $clipBox['y'], $clipBox['width'], $clipBox['height']);
    }

    private function buildDecorationPath(LayoutDecoration $decoration): string
    {
        $radius = min(
            max($decoration->borderRadius, 0.0),
            $decoration->width / 2.0,
            $decoration->height / 2.0,
        );

        if ($radius <= 0.0) {
            return sprintf('%F %F %F %F re', $decoration->x, $decoration->y, $decoration->width, $decoration->height);
        }

        $kappa = 0.5522847498;
        $control = $radius * $kappa;
        $left = $decoration->x;
        $bottom = $decoration->y;
        $right = $decoration->x + $decoration->width;
        $top = $decoration->y + $decoration->height;

        return implode("\n", [
            sprintf('%F %F m', $left + $radius, $bottom),
            sprintf('%F %F l', $right - $radius, $bottom),
            sprintf(
                '%F %F %F %F %F %F c',
                $right - $radius + $control,
                $bottom,
                $right,
                $bottom + $radius - $control,
                $right,
                $bottom + $radius,
            ),
            sprintf('%F %F l', $right, $top - $radius),
            sprintf(
                '%F %F %F %F %F %F c',
                $right,
                $top - $radius + $control,
                $right - $radius + $control,
                $top,
                $right - $radius,
                $top,
            ),
            sprintf('%F %F l', $left + $radius, $top),
            sprintf(
                '%F %F %F %F %F %F c',
                $left + $radius - $control,
                $top,
                $left,
                $top - $radius + $control,
                $left,
                $top - $radius,
            ),
            sprintf('%F %F l', $left, $bottom + $radius),
            sprintf(
                '%F %F %F %F %F %F c',
                $left,
                $bottom + $radius - $control,
                $left + $radius - $control,
                $bottom,
                $left + $radius,
                $bottom,
            ),
            'h',
        ]);
    }
}
