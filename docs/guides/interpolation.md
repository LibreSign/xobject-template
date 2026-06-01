<!-- SPDX-FileCopyrightText: 2026 LibreSign -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Context interpolation

Source example: `examples/interpolation.php`

## Placeholder syntax

The current interpolation feature supports placeholders like:

- `{{ name }}`
- `{{ role }}`

## Behavior

- Interpolation happens before HTML subset parsing.
- Values are HTML-escaped before insertion.
- Unknown placeholders remain unchanged.

This is useful for signature labels and document workflow overlays where a template shape is fixed but content changes per signer/document.
