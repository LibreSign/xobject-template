<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate;

use InvalidArgumentException;
use LibreSign\XObjectTemplate\Dto\CompileResult;
use LibreSign\XObjectTemplate\Dto\PlainTextBlock;
use LibreSign\XObjectTemplate\Layout\LayoutResult;
use LibreSign\XObjectTemplate\Layout\LayoutStyleResolver;
use LibreSign\XObjectTemplate\Layout\TextBoxLayouter;
use LibreSign\XObjectTemplate\Pdf\StandardFontMetrics;
use LibreSign\XObjectTemplate\Pdf\TemplateDocumentBuilder;

/** Compiles literal text without HTML parsing or template interpolation. */
final readonly class PlainTextCompiler
{
    private TextBoxLayouter $layouter;

    public function __construct(private TemplateDocumentBuilder $documentBuilder = new TemplateDocumentBuilder())
    {
        $metrics = new StandardFontMetrics($documentBuilder->unicodeFonts(), $documentBuilder->complexShaper());
        $this->layouter = new TextBoxLayouter(new LayoutStyleResolver(), $metrics);
    }

    /** @param list<PlainTextBlock> $blocks */
    public function compile(array $blocks, float $width, float $height): CompileResult
    {
        if (!is_finite($width) || !is_finite($height) || $width <= 0.0 || $height <= 0.0) {
            throw new InvalidArgumentException('Canvas dimensions must be finite and positive.');
        }

        $start = hrtime(true);
        $lines = [];
        foreach ($blocks as $block) {
            array_push($lines, ...$this->layouter->layoutLiteral($block, $height));
        }

        return $this->documentBuilder->buildLayout(
            new LayoutResult($lines, []),
            $width,
            $height,
            $start,
            0,
        );
    }
}
