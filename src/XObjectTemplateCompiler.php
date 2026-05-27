<?php

declare(strict_types=1);

namespace LibreSign\XObjectTemplate;

use LibreSign\XObjectTemplate\Contract\XObjectTemplateCompilerInterface;
use LibreSign\XObjectTemplate\Dto\CompileRequest;
use LibreSign\XObjectTemplate\Dto\CompileResult;
use LibreSign\XObjectTemplate\Html\SubsetHtmlParser;
use LibreSign\XObjectTemplate\Layout\LinearLayoutEngine;
use LibreSign\XObjectTemplate\Pdf\ColorParser;
use LibreSign\XObjectTemplate\Pdf\PdfEscaper;

final class XObjectTemplateCompiler implements XObjectTemplateCompilerInterface
{
    private SubsetHtmlParser $htmlParser;
    private LinearLayoutEngine $layoutEngine;
    private PdfEscaper $pdfEscaper;
    private ColorParser $colorParser;

    public function __construct(
        ?SubsetHtmlParser $htmlParser = null,
        ?LinearLayoutEngine $layoutEngine = null,
        ?PdfEscaper $pdfEscaper = null,
        ?ColorParser $colorParser = null,
    ) {
        $this->htmlParser = $htmlParser ?? new SubsetHtmlParser();
        $this->layoutEngine = $layoutEngine ?? new LinearLayoutEngine();
        $this->pdfEscaper = $pdfEscaper ?? new PdfEscaper();
        $this->colorParser = $colorParser ?? new ColorParser();
    }

    public function compile(CompileRequest $request): CompileResult
    {
        $start = hrtime(true);

        $nodes = $this->htmlParser->parse($request->html);
        $layout = $this->layoutEngine->layout($nodes, $request->width, $request->height);

        $stream = [];
        $stream[] = 'q';

        foreach ($layout->images as $image) {
            $stream[] = sprintf('q %F 0 0 %F %F %F cm /%s Do Q', $image->width, $image->height, $image->x, $image->y, $image->alias);
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

        $resources = [
            'Font' => [
                'F1' => [
                    'Type' => '/Font',
                    'Subtype' => '/Type1',
                    'BaseFont' => '/Helvetica',
                ],
            ],
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

        return new CompileResult(
            contentStream: implode("\n", $stream),
            resources: $resources,
            bbox: [0.0, 0.0, $request->width, $request->height],
            metadata: [
                'render_ms' => round((hrtime(true) - $start) / 1_000_000, 3),
                'node_count' => count($nodes),
                'line_count' => count($layout->lines),
                'image_count' => count($layout->images),
            ],
        );
    }
}
