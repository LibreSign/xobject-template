<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate;

use LibreSign\XObjectTemplate\Contract\XObjectTemplateCompilerInterface;
use LibreSign\XObjectTemplate\Dto\CompileRequest;
use LibreSign\XObjectTemplate\Dto\CompileResult;
use LibreSign\XObjectTemplate\Exception\UnsupportedSubsetException;
use LibreSign\XObjectTemplate\Html\SubsetHtmlParser;
use LibreSign\XObjectTemplate\Layout\LinearLayoutEngine;
use LibreSign\XObjectTemplate\Pdf\ColorParser;
use LibreSign\XObjectTemplate\Pdf\PdfEscaper;
use LibreSign\XObjectTemplate\Pdf\TemplateDocumentBuilder;

final readonly class XObjectTemplateCompiler implements XObjectTemplateCompilerInterface
{
    private SubsetHtmlParser $htmlParser;
    private LinearLayoutEngine $layoutEngine;
    private TemplateDocumentBuilder $documentBuilder;

    public function __construct(
        ?SubsetHtmlParser $htmlParser = null,
        ?LinearLayoutEngine $layoutEngine = null,
        ?PdfEscaper $pdfEscaper = null,
        ?ColorParser $colorParser = null,
        ?TemplateDocumentBuilder $documentBuilder = null,
    ) {
        $this->htmlParser = $htmlParser ?? new SubsetHtmlParser();
        $this->layoutEngine = $layoutEngine ?? new LinearLayoutEngine();
        $this->documentBuilder = $documentBuilder ?? new TemplateDocumentBuilder(
            $pdfEscaper ?? new PdfEscaper(),
            $colorParser ?? new ColorParser(),
        );
    }

    /**
     * Compile the supported HTML+CSS subset into a reusable PDF Form XObject payload.
     *
     * @throws UnsupportedSubsetException If the HTML fragment contains an unsupported element.
     */
    public function compile(CompileRequest $request): CompileResult
    {
        $start = hrtime(true);

        $nodes = $this->htmlParser->parse($request->html);
        $layout = $this->layoutEngine->layout($nodes, $request->width, $request->height);

        return $this->documentBuilder->build($request, $layout, $start, count($nodes));
    }
}
