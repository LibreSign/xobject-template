<!-- SPDX-FileCopyrightText: 2026 LibreSign -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Maintainer notes

## Documentation deployment

- Pull requests validate docs only.
- Pushes to `main` validate and publish the generated site to `gh-pages`.
- GitHub Pages should be configured as: deploy from branch `gh-pages`, folder `/root`.

## Validation flow

- Execute `composer docs:test`.
- Ensure examples in `examples/` remain runnable.
- Keep support references aligned with parser/layout/SVG behavior and tests.

## Scope control

- Keep user docs focused on public contracts.
- Avoid scope creep into browser-engine semantics or full PDF-signing behavior.
- Keep LibreSign use case visible without making this package LibreSign-only.

## Drift policy

- Support matrix docs are checked by `scripts/check-docs-drift.php` against known code-level indicators.
- TODO: add finer-grained automated extraction for CSS property lists if parser/layout structure changes substantially.
