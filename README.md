<!-- SPDX-FileCopyrightText: 2026 LibreSign -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# xobject-template

> Compile a minimal HTML+CSS subset into reusable PDF Form XObject templates for visible signatures.

`xobject-template` is a focused rendering engine for digital-signature ecosystems that need **beautiful, vector-first, stable** appearance templates with predictable performance.

## Why this package

- Product-ready visible signature templates (text + image) as real XObject payloads
- Clean integration path for `pdf-signer-php` and LibreSign-like platforms
- Lean API designed for long-term compatibility and monetizable maintainability

## Quick integration

Use the compiler to generate `content stream`, `resources`, and `bbox`, then map the output to your signature appearance DTO adapter.
