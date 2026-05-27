<?php

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf;

use LibreSign\XObjectTemplate\Dto\CompileRequest;
use LibreSign\XObjectTemplate\Dto\CompileResult;
use LibreSign\XObjectTemplate\Layout\LayoutImage;
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

    /**
     * @param array<string, array<string, mixed>> $fontResources
     */
    public function __construct(
        private PdfEscaper $pdfEscaper = new PdfEscaper(),
        private ColorParser $colorParser = new ColorParser(),
        private array $fontResources = self::DEFAULT_FONT_RESOURCES,
    ) {
    }

    public function build(
        CompileRequest $request,
        LayoutResult $layout,
        int $startedAtNs,
        int $nodeCount = 0,
    ): CompileResult
    {
        return new CompileResult(
            contentStream: $this->buildContentStream($layout),
            resources: $this->buildResources($layout),
            bbox: [0.0, 0.0, $request->width, $request->height],
            metadata: $this->buildMetadata($layout, $startedAtNs, $nodeCount),
        );
    }

    public function buildContentStream(LayoutResult $layout): string
    {
        $stream = ['q'];

        foreach ($layout->images as $image) {
            $stream[] = $this->buildImageCommand($image);
        }

        $stream[] = 'BT';
        foreach ($layout->lines as $line) {
            $stream[] = sprintf('/%s %F Tf', $line->fontAlias, $line->fontSize);
            $stream[] = $this->colorParser->toPdfRgb($line->rgbColor);
            $stream[] = sprintf('%F %F Td', $line->x, $line->y);
            $stream[] = sprintf('(%s) Tj', $this->pdfEscaper->escapeLiteralString($line->text));
        }
        $stream[] = 'ET';
        $stream[] = 'Q';

        return implode("\n", $stream);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildResources(LayoutResult $layout): array
    {
        $resources = [
            'Font' => $this->fontResources,
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
            'render_ms' => round((hrtime(true) - $startedAtNs) / 1_000_000, 3),
            'line_count' => count($layout->lines),
            'image_count' => count($layout->images),
            'node_count' => $nodeCount,
        ];
    }

    public function withFontResources(array $fontResources): self
    {
        return new self($this->pdfEscaper, $this->colorParser, $fontResources);
    }

    private function buildImageCommand(LayoutImage $image): string
    {
        return sprintf(
            'q %F 0 0 %F %F %F cm /%s Do Q',
            $image->width,
            $image->height,
            $image->x,
            $image->y,
            $image->alias,
        );
    }
}