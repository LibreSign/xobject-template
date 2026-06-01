<!-- SPDX-FileCopyrightText: 2026 LibreSign -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Preview PDF

Source example: `examples/preview-pdf.php`

## Why preview export is useful

`xobject-template` compiles Form XObject payloads. For visual debugging, `SinglePagePdfExporter` can wrap a compile result into a one-page PDF using the result `bbox`.

This makes template iteration easier because you can quickly inspect appearance output without wiring a full downstream signer flow.

## What the example does

- Compiles a simple template.
- Exports a preview PDF.
- Writes output to `build/examples/preview.pdf`.

## Scope reminder

Preview export is a helper for inspection and integration tests. Production signing/placement still happens in your downstream PDF workflow.
