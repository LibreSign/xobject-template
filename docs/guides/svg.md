<!-- SPDX-FileCopyrightText: 2026 LibreSign -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# SVG

Source example: `examples/svg.php`

## Current SVG usage path

SVG is handled through image source input (`<img src="...svg">`) and then converted to a PDF Form XObject by the current SVG implementation.

## Typical use cases

- deterministic logos,
- deterministic icons,
- deterministic vector shape overlays in signature appearances.

## Important scope limits

SVG support is intentionally scoped and deterministic. It is not a full browser-grade SVG engine.

See [Reference / Supported SVG](../reference/supported-svg.md) for exact supported primitives, path commands, transforms, paint behavior, and known unsupported features.
