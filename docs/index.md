<!-- SPDX-FileCopyrightText: 2026 LibreSign -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# xobject-template documentation

This is the front door for the public docs site.

If you're a developer trying to use `xobject-template`, start with the install guide, then open an example, then check a real use case.

`xobject-template` compiles a constrained HTML/CSS subset into PDF Form XObject output.

## Start here

- [Getting started](source/guides/)
- [Examples](source/reference/)
- [Visible signature use case](source/use-cases/)

## Repository layout

- `src/` — library code
- `tests/` — unit, integration, and documentation tests
- `docs/` — public documentation pages
- `docs/source/` — editable source pages and examples

## Quick example

```php
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

For executable samples, see the [examples page](source/reference/).
