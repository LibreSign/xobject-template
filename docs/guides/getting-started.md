<!-- SPDX-FileCopyrightText: 2026 LibreSign -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Getting started

## Install

```bash
composer require libresign/xobject-template
```

## Render a template

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

## Validate the examples

Run the documentation test suite to execute every example file and verify the generated artifacts:

```bash
composer run examples:test
```

## Where to look next

- [Examples](../reference/examples.md)
- [Visible signature use case](../use-cases/visible-signatures.md)
