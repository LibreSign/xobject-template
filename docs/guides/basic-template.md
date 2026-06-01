<!-- SPDX-FileCopyrightText: 2026 LibreSign -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Basic template

Source example: `examples/basic-template.php`

## What the example demonstrates

- HTML input with inline styles.
- `CompileRequest` with `width`, `height`, and optional `context`.
- Compilation through `XObjectTemplateCompiler`.
- Inspection of `contentStream`, `resources`, `bbox`, and `metadata`.

## Expected output

The example writes `build/examples/basic-template-result.json` and includes:

- non-empty content stream,
- font resources,
- a 4-value bounding box,
- render metadata.

Use this as your baseline before moving to images, SVG, and placement guides.
