<!-- SPDX-FileCopyrightText: 2026 LibreSign -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# xobject-template

> Compile a minimal HTML+CSS subset into reusable PDF Form XObject payloads.

`xobject-template` is a focused rendering engine for projects that need **beautiful, vector-first, stable** reusable overlays inside PDF workflows.

## Why this package

- Reusable XObject payloads for labels, stamps, approvals, and other document overlays
- Clean integration path for any PDF pipeline that can embed Form XObjects
- Lean API designed for long-term compatibility and monetizable maintainability

## Quick integration

Use the compiler to generate a reusable XObject result. Consumers that prefer arrays over DTOs can adapt the result into a generic payload shape.

```php
use LibreSign\XObjectTemplate\Dto\CompileRequest;
use LibreSign\XObjectTemplate\Integration\XObjectPayloadAdapter;
use LibreSign\XObjectTemplate\XObjectTemplateCompiler;

$compiler = new XObjectTemplateCompiler();

$result = $compiler->compile(new CompileRequest(
	html: '<div style="font-size:10px;color:#111111">Rendered for Alice</div>'
		. '<img src="/tmp/example-image.png" style="width:24px;height:24px" />',
	width: 240.0,
	height: 84.0,
));

$payload = (new XObjectPayloadAdapter())->toXObjectPayload($result);
```

### Output contract

- `$result->contentStream`: PDF operators ready for a Form XObject stream
- `$result->resources`: font/image resource dictionary keyed for PDF serialization
- `$result->bbox`: bounding box as `[x1, y1, x2, y2]`
- `$result->metadata`: render diagnostics such as `line_count`, `image_count`, `node_count`, and `render_ms`
- `$payload`: transport-agnostic array with `stream`, `resources`, and `bbox`

## Supported HTML/CSS subset

### HTML

- Supported elements: `<div>`, `<p>`, `<span>`, `<br>`, `<img>`
- Text fragments are normalized into inline text nodes internally
- Inline styles are read from the `style` attribute
- Images use the `src` attribute as the source reference included in the resource dictionary

### CSS used by the renderer

- Typography: `font-size`, `font-family`, `font-weight`, `line-height`, `color`
- Layout: `margin`, `padding`, `text-align`, `width`, `height`
- Numeric values can be provided as unitless numbers or `px`
- `px` values are converted to PDF points using the package conversion rules
- Unknown or incomplete CSS declarations are ignored instead of aborting the render

### Rendering notes

- Font family mapping currently targets the built-in Helvetica, Times, and Courier aliases used by the generated PDF resources
- `img` width/height fall back to `32x32` when omitted or invalid
- Image and text placement are clamped to the requested output box
- The compiler output is not tied to any single downstream package; any consumer that understands Form XObject stream/resources/bbox data can use it

## Failure modes

- Unsupported HTML elements raise `UnsupportedSubsetException`
- Malformed HTML fragments are normalized by `DOMDocument` before traversal
- Empty text nodes and invalid inline-style fragments are ignored during parsing/rendering
