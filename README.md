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
use LibreSign\XObjectTemplate\Pdf\SinglePagePdfExporter;
use LibreSign\XObjectTemplate\XObjectTemplateCompiler;

$compiler = new XObjectTemplateCompiler();

$result = $compiler->compile(new CompileRequest(
	html: '<div style="font-size:10px;color:#111111">Rendered for Alice</div>'
		. '<img src="/tmp/example-image.png" style="width:24px;height:24px" />',
	width: 240.0,
	height: 84.0,
));

$payload = (new XObjectPayloadAdapter())->toXObjectPayload($result);
$pdf = (new SinglePagePdfExporter())->export($result);

file_put_contents(__DIR__ . '/build/preview.pdf', $pdf);
```

### Standalone PDF export

`SinglePagePdfExporter` wraps a compiled XObject result into a one-page PDF whose `MediaBox` matches the compiled `bbox` size exactly.

- The page size is derived from `$result->bbox`
- Non-zero bounding boxes are translated back to the page origin automatically
- Local PNG and JPEG image sources are embedded into the standalone PDF during export

### Output contract

- `$result->contentStream`: PDF operators ready for a Form XObject stream
- `$result->resources`: font/image resource dictionary keyed for PDF serialization
- `$result->bbox`: bounding box as `[x1, y1, x2, y2]`
- `$result->metadata`: render diagnostics such as `line_count`, `image_count`, `node_count`, and `render_ms`
- `$payload`: transport-agnostic array with `stream`, `resources`, and `bbox`
- `$pdf`: standalone PDF bytes ready to save, stream, or attach to preview workflows

### Scaling a compiled XObject

`CompileRequest::width` and `CompileRequest::height` define the base design size of the template.
If a downstream consumer needs to place the compiled stamp at a different size while preserving the
original aspect ratio, it should keep the compiled XObject unchanged and apply a uniform scale during
PDF placement instead of recompiling the HTML with new dimensions.

- Read the base size from `$result->bbox`
- Compute a single scale factor from the target width or target height
- Apply the same scale to both axes in the placement matrix

```php
[$minX, $minY, $maxX, $maxY] = $result->bbox;

$baseWidth = $maxX - $minX;
$baseHeight = $maxY - $minY;

$targetWidth = 175.0;
$scale = $targetWidth / $baseWidth;
$targetHeight = $baseHeight * $scale;

// Consumer-side PDF placement concept:
$placement = sprintf(
	'q %F 0 0 %F %F %F cm /Fm0 Do Q',
	$scale,
	$scale,
	$x,
	$y,
);
```

Using a uniform placement scale keeps text, images, spacing, and line breaks visually aligned.
Recompiling only to emulate a proportional resize is usually the wrong integration point for this
package.

For consumers that want a small helper instead of recalculating the matrix manually, the package also
ships `LibreSign\XObjectTemplate\Integration\XObjectPlacementCalculator` and
`LibreSign\XObjectTemplate\Integration\XObjectPlacement`.

```php
use LibreSign\XObjectTemplate\Integration\XObjectPlacementCalculator;

$placement = (new XObjectPlacementCalculator())->fromWidth($result, 175.0, 36.0, 72.0);

$pdfCommand = $placement->toPdfCommand('Fm0');
// q 0.729167 0 0 0.729167 36.000000 72.000000 cm /Fm0 Do Q
```

### Optional context interpolation

If the caller passes `CompileRequest::context`, the compiler can interpolate simple `{{ key }}`
placeholders before parsing the HTML subset.

- Values are HTML-escaped before insertion
- Unknown placeholders are left untouched
- Twig users can keep rendering HTML upstream and skip this feature entirely

## Supported HTML/CSS subset

### HTML

- Supported elements: `<div>`, `<p>`, `<span>`, `<br>`, `<img>`
- Text fragments are normalized into inline text nodes internally
- Inline styles are read from the `style` attribute
- Images use the `src` attribute as the source reference included in the resource dictionary

### CSS used by the renderer

- Typography: `font-size`, `font-family`, `font-weight`, `line-height`, `color`, `text-align`, `hyphens`, `white-space`
- Layout: `margin`, `padding`, `width`, `height`, `overflow`, `text-overflow`
- Vector box styling: `background-color`, `border-color`, `border-width`, `border-radius`
- Structured layout: `display:flex`, `flex-direction`, `justify-content`, `align-items`, `gap`
- Absolute placement: `position:absolute`, `top`, `right`, `bottom`, `left`
- Numeric values can be provided as unitless numbers or `px`; `width`, `height`, and positional offsets also accept `%`
- `px` values are converted to PDF points using the package conversion rules
- Unknown or incomplete CSS declarations are ignored instead of aborting the render

### Rendering notes

- Font family mapping currently targets the built-in Helvetica, Times, and Courier aliases used by the generated PDF resources
- Text alignment uses measured widths for left, center, right, and basic justified output (`Tw` word spacing)
- Hyphenation supports a small deterministic subset: `hyphens:auto`, `hyphens:manual` with soft hyphens, and `hyphens:none`
- Overflow clipping uses PDF clipping paths; `text-overflow:ellipsis` applies when hidden overflow truncates visible text
- Backgrounds and borders are emitted as vector rectangles, including rounded corners
- Percentage-based sizing and offsets resolve relative to the current layout container
- Flex layouts are intentionally small-scope and predictable: the engine supports deterministic row/column compositions for stamps, labels, and approval blocks rather than full browser-grade CSS
- `img` width/height fall back to `32x32` when omitted or invalid
- Image and text placement are clamped to the requested output box
- The compiler output is not tied to any single downstream package; any consumer that understands Form XObject stream/resources/bbox data can use it

## Failure modes

- Unsupported HTML elements raise `UnsupportedSubsetException`
- Malformed HTML fragments are normalized by `DOMDocument` before traversal
- Empty text nodes and invalid inline-style fragments are ignored during parsing/rendering
