<!-- SPDX-FileCopyrightText: 2026 LibreSign -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# xobject-template

Minimal HTML+CSS to reusable PDF Form XObject compiler for visible signature appearances and document overlays.

## What is xobject-template?

`xobject-template` is a focused PHP rendering engine that compiles a deterministic HTML/CSS subset into Form XObject-oriented output (`contentStream`, `resources`, `bbox`) for downstream PDF workflows.

## Why it exists

Many systems need stable visual signature/stamp/label appearances, but do not want browser-grade rendering complexity inside PDF pipelines.

This package provides a scoped, integration-friendly rendering layer for that gap.

## Install

```bash
composer require libresign/xobject-template
```

## Quick example

```php
<?php

use LibreSign\XObjectTemplate\Dto\CompileRequest;
use LibreSign\XObjectTemplate\XObjectTemplateCompiler;

$compiler = new XObjectTemplateCompiler();

$result = $compiler->compile(new CompileRequest(
    html: '<div style="font-size:12;color:#111111">Signed by {{ name }}</div>',
    width: 240.0,
    height: 84.0,
    context: ['name' => 'Alice'],
));

// $result->contentStream
// $result->resources
// $result->bbox
```

## LibreSign use case

- LibreSign project: <https://github.com/LibreSign/libresign>

## Contributing

- Repository guide: `CONTRIBUTING.md`

If this package helps your project generate reliable PDF signature appearances, please star the repository. It helps other developers discover the project and signals that this work is worth maintaining.

## Support the project

- Sponsor maintenance: <https://github.com/sponsors/LibreSign>

