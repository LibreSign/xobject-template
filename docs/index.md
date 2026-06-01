<!-- SPDX-FileCopyrightText: 2026 LibreSign -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# xobject-template documentation

This is the public documentation landing page for the repository.
The full hand-written source lives under `docs/source/`.

## What this library does

`xobject-template` compiles a constrained HTML/CSS subset into reusable PDF Form XObject output.
It is designed for predictable signature appearances, labels, stamps, overlays, and other compact PDF decorations.

## Start here

- [Getting started](source/guides/)
- [Examples](source/reference/)
- [Visible signature use case](source/use-cases/)

## Highlights

- deterministic rendering for PDF workflows,
- a focused PHP API,
- runnable examples covered by tests,
- a small documentation surface that is easier to maintain.

## Repository layout

- `src/` — library code
- `tests/` — unit, integration, and documentation tests
- `docs/` — public documentation pages
- `docs/source/` — source pages and example files

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
