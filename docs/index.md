<!-- SPDX-FileCopyrightText: 2026 LibreSign -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# xobject-template

`xobject-template` compiles a focused HTML+CSS subset into reusable PDF Form XObject payloads.

It is designed for deterministic visual appearance generation in digital-signature and document-workflow systems: signature appearances, stamps, labels, and overlays.

It solves a practical integration problem: many PDF signers can place Form XObjects but do not want to implement HTML/CSS rendering.

## What this package does

- Renders text from a minimal HTML/CSS subset.
- Supports context interpolation for template personalization.
- Supports PNG/JPEG image embedding through file sources.
- Supports scoped SVG rendering through current implementation support.
- Produces reusable output contracts (`contentStream`, `resources`, `bbox`, metadata).

## What this package is not

- A full PDF signer.
- A browser engine.
- A generic PDF editor.
- A complete HTML/CSS/SVG renderer.

## Start quickly

Go to [Getting started](getting-started.md) for a runnable example based on `examples/basic-template.php`.

## Key references

- [Supported HTML and CSS](reference/supported-html-css.md)
- [Supported SVG](reference/supported-svg.md)
- [LibreSign use case](use-cases/libresign.md)
- [Contributing](community/contributing.md)
- [Sustainability](community/sustainability.md)

If this package helps your project generate reliable PDF signature appearances, consider starring the repository, contributing a fixture, or sponsoring maintenance.
