<!-- SPDX-FileCopyrightText: 2026 LibreSign -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# xobject-template documentation

`xobject-template` compiles a HTML/CSS template into PDF Form XObject output.

## Start here

- [Getting started](guides/getting-started.md)
- [Examples](reference/examples.md)
- [Visible signature use case](use-cases/visible-signatures.md)

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
```

For executable samples, see the [examples page](reference/examples.md).
