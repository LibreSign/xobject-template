<!-- SPDX-FileCopyrightText: 2026 LibreSign -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Visible signature appearances

This library exists to support cases where a PDF needs a predictable visible appearance:

- signature labels,
- approval stamps,
- overlay annotations,
- status blocks,
- other compact PDF decorations.

## Why this matters

PDF pipelines usually need stable layout more than browser-grade rendering. `xobject-template` keeps the surface area small so the output is easier to test and reason about.

## Typical flow

1. Build a `CompileRequest`.
2. Pass HTML, dimensions, and context to `XObjectTemplateCompiler`.
3. Consume the generated `contentStream`, `resources`, and `bbox` in downstream PDF tooling.

## Related pages

- [Getting started](../guides/getting-started.md)
- [Examples](../reference/examples.md)
