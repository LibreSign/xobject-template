<!-- SPDX-FileCopyrightText: 2026 LibreSign -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# XObject Template

Minimal HTML+CSS to reusable PDF Form XObject compiler for visible signature appearances and document overlays.

## What is xobject-template?

`xobject-template` is a focused PHP rendering engine that compiles a deterministic HTML/CSS subset into Form XObject-oriented output (`contentStream`, `resources`, `bbox`) for downstream PDF workflows.

## Why it exists

Many systems need stable visual signature/stamp/label appearances, but do not want browser-grade rendering complexity inside PDF pipelines.

This package provides a scoped, integration-friendly rendering layer for that gap.

## Install

```bash
composer require libresign/xobject-template
```

## Quick example

```php
<?php

use LibreSign\XObjectTemplate\Dto\CompileRequest;
use LibreSign\XObjectTemplate\XObjectTemplateCompiler;

$compiler = new XObjectTemplateCompiler();

$result = $compiler->compile(new CompileRequest(
    html: '<div style="font-size:12;color:#111111">Signed by {{ name }}</div>',
    width: 240.0,
    height: 84.0,
    context: ['name' => 'Alice'],
));

// $result->contentStream
// $result->resources
// $result->bbox
```

## Literal text boxes

Use `PlainTextCompiler` for already resolved text that must not be interpreted as HTML or
as a template. It shares the font metrics, line breaker, positioning and PDF writer with
the HTML compiler.

```php
use LibreSign\XObjectTemplate\Dto\PlainTextBlock;
use LibreSign\XObjectTemplate\Pdf\TemplateDocumentBuilder;
use LibreSign\XObjectTemplate\PlainTextCompiler;

$compiler = new PlainTextCompiler(
    (new TemplateDocumentBuilder())->withUnicodeFontFiles(['/path/to/font.ttf']),
);
$result = $compiler->compile([
    new PlainTextBlock("Signé par Renée\nÉmetteur : Example", 2, 2, 236, 80),
], width: 240, height: 84);
```

Boxes use points measured from the top-left, wrap long words at grapheme boundaries
without inserting hyphens, preserve explicit line breaks and normalize text to NFC.
Text is clipped to its box; only complete lines that fit vertically are included.
Horizontal tabs are treated as spaces, and wrapping may collapse regular spaces.
Configure fonts with coverage for the intended scripts, as with the HTML compiler.

Use `SignerPhpCoreXObjectResourceBuilder::resourcesBuilder()` when passing the result
to a signing appearance. The adapter materializes the generated font dictionaries
and omits empty resource dictionaries rather than serializing them as arrays.

## LibreSign use case

- LibreSign project: <https://github.com/LibreSign/libresign>

## Contributing

- Repository guide: `CONTRIBUTING.md`

If this package helps your project generate reliable PDF signature appearances, please star the repository. It helps other developers discover the project and signals that this work is worth maintaining.

## Support the project

- Sponsor maintenance: <https://github.com/sponsors/LibreSign>

