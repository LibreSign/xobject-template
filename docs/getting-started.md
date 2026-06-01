<!-- SPDX-FileCopyrightText: 2026 LibreSign -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Getting started

This page uses the real example file `examples/basic-template.php`.

## Minimal compile flow

The example creates a `CompileRequest`, calls `XObjectTemplateCompiler::compile()`, and writes a JSON output summary under `build/examples/`.

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
```

## Result at user level

The compile result provides:

- `contentStream`: PDF operators for a Form XObject stream.
- `resources`: font/image resource dictionary data for downstream serialization.
- `bbox`: `[x1, y1, x2, y2]` bounding box.
- `metadata`: rendering diagnostics (`render_ms`, `line_count`, `image_count`, `node_count`).

## Next steps

- Basic usage details: [Guides / Basic template](guides/basic-template.md)
- Placement/scaling in downstream PDFs: [Guides / Placement and scaling](guides/placement.md)
- Full output contract: [Reference / Output contract](reference/output-contract.md)

If this package helps your project generate reliable PDF signature appearances, consider starring the repository, contributing a fixture, or sponsoring maintenance.
