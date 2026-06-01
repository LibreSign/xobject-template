<!-- SPDX-FileCopyrightText: 2026 LibreSign -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# xobject-template documentation

`xobject-template` turns a constrained HTML/CSS subset into reusable PDF Form XObject output for signatures, stamps, labels, and overlays.

## Start here

- [Getting started](guides/)
- [Examples](reference/)
- [Visible signature use case](use-cases/)

## What you get

- deterministic rendering for PDF workflows,
- a focused PHP API,
- runnable examples that are covered by tests,
- a small documentation footprint that is easier to maintain.

## Repository layout

- `src/` — library code
- `tests/` — unit, integration, and documentation tests
- `docs/source/` — documentation pages intended for publication
- `docs/source/examples/` — executable examples with fixtures

## Quick preview

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

For executable samples, see the [examples page](reference/).
