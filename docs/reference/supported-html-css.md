<!-- SPDX-FileCopyrightText: 2026 LibreSign -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Supported HTML and CSS

This page reflects current implementation and tests.

## HTML elements

Supported:

- `<div>`
- `<p>`
- `<span>`
- `<br>`
- `<img>`

Not supported (examples): tables, lists, semantic formatting tags like `<strong>`.

## CSS properties used by renderer

Common supported properties include:

- Typography: `font-size`, `font-family`, `font-weight`, `line-height`, `color`, `text-align`, `hyphens`, `white-space`
- Layout/box: `margin`, `padding`, `width`, `height`, `overflow`, `text-overflow`
- Decoration: `background-color`, `border-color`, `border-width`, `border-radius`
- Flex subset: `display:flex`, `flex-direction`, `justify-content`, `align-items`, `gap`
- Absolute positioning: `position:absolute`, `top`, `right`, `bottom`, `left`

## Units and values

- Unitless numeric values are accepted for many properties.
- `px` is accepted and converted to PDF points.
- `%` is supported for dimensions/offsets in current layout implementation.
- Unknown or invalid declarations are ignored, not fatal.

## Practical notes

- Font alias mapping targets built-in Helvetica/Times/Courier variants.
- Overflow clipping uses PDF clipping paths (`overflow:hidden`).
- The behavior is deterministic and intentionally scoped for overlays/stamps, not browser parity.

See [Limitations](limitations.md) before designing production templates.
