<!-- SPDX-FileCopyrightText: 2026 LibreSign -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Examples

The examples are executable PHP files under `docs/source/examples/`.
Each one is covered by `tests/Documentation/ExamplesTest.php`.

## Included examples

- `basic-template.php` — compile a simple template with a context variable
- `preview-pdf.php` — export a single-page PDF preview
- `images.php` — embed PNG and JPEG assets from base64 fixtures
- `svg.php` — render SVG content as an image source
- `placement.php` — demonstrate placement calculations
- `interpolation.php` — interpolate context variables in text

## How to run them

```bash
composer run examples:test
```

## Output files

The examples write their generated artifacts to `build/examples/` so the repository stays clean and the outputs remain disposable.
