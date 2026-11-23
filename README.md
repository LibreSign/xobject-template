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

## LibreSign use case

- LibreSign project: <https://github.com/LibreSign/libresign>

## Contributing

- Repository guide: `CONTRIBUTING.md`

If this package helps your project generate reliable PDF signature appearances, please star the repository. It helps other developers discover the project and signals that this work is worth maintaining.

## Support the project

- Sponsor maintenance: <https://github.com/sponsors/LibreSign>



## Unicode text in PDF XObjects

The built-in PDF Type1 fonts only support limited character encodings. To render
Unicode text, provide one or more embeddable TrueType (`.ttf`) font files when
constructing the template compiler:

```php
use LibreSign\XObjectTemplate\Dto\CompileRequest;
use LibreSign\XObjectTemplate\Pdf\SinglePagePdfExporter;
use LibreSign\XObjectTemplate\Pdf\TemplateDocumentBuilder;
use LibreSign\XObjectTemplate\XObjectTemplateCompiler;

$builder = (new TemplateDocumentBuilder())->withUnicodeFontFiles([
    '/path/to/DejaVuSans.ttf',
    '/path/to/CJK-fallback.ttf',
]);
$compiler = new XObjectTemplateCompiler(documentBuilder: $builder);
$result = $compiler->compile(new CompileRequest(
    html: '<div>Signé par Renée</div>',
));
$pdf = (new SinglePagePdfExporter())->export($result);
```

Fonts are searched in the order supplied. Each Unicode line must be covered by
one font; otherwise compilation fails rather than silently emitting corrupted
text. ASCII-only content retains the existing font resources and content stream.
The PDF preview exporter embeds the selected TrueType font, its CID-to-glyph map,
and a `ToUnicode` map for text extraction. **No font binaries are bundled**:
applications must supply fonts with appropriate embedding rights. Embedding
complete font files rather than subsets can increase document size.

Basic joining for Arabic presentation forms and right-to-left ordering of Arabic
and Hebrew runs are included. Full OpenType shaping, complex-script ligatures,
bidirectional paragraph layout, mixed-font spans and all Unicode scripts are
**not guaranteed**. Applications requiring these must integrate a complete text
shaping engine.

**SignerPHP integration:** the resources for embedded fonts include additional
internal descriptors used by `SinglePagePdfExporter`. They cannot yet be passed
directly to `SignatureAppearanceXObjectDto`, whose current resource serializer
does not create font file streams and indirect PDF objects. That integration
requires a separate follow-up in SignerPHP before LibreSign can use these Unicode
font resources in signed PDFs.
