<!-- SPDX-FileCopyrightText: 2026 LibreSign -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Installation

## Requirements

From `composer.json`:

- PHP: `^8.2`
- Required PHP extensions: no explicit extra extensions declared by this package.

Runtime notes:

- The HTML parser uses `DOMDocument`.
- Image metadata detection uses `getimagesizefromstring` for raster images.
- The package reads local image files for image embedding/export workflows.

## Install with Composer

```bash
composer require libresign/xobject-template
```

## Verify installation

A quick verification is to run one example from this repository after dependencies are installed:

- `examples/basic-template.php`

See [Getting started](getting-started.md) for the exact flow and output expectations.
