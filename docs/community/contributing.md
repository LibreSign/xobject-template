<!-- SPDX-FileCopyrightText: 2026 LibreSign -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Contributing

Contributions are welcome. Keep quality high and changes scoped.

The repository-level governance lives in the root file:

- `CONTRIBUTING.md`

Use this page as a **docs/examples complement**, not as a replacement for root contribution policy.

## Docs and examples workflow

From repository root, run:

- `composer lint`
- `composer test:unit`
- `composer test:integration`
- `composer docs:test`

## Documentation and examples rules

- Add/update runnable examples in `examples/`.
- Keep examples small and deterministic.
- Ensure docs snippets come from real examples.
- Run docs drift checks before opening a PR.

## When adding supported behavior

When adding HTML/CSS/SVG support:

1. Add/adjust implementation.
2. Add or update fixture-based tests.
3. Update support reference pages.
4. Keep limitations explicit (do not overstate support).

## Reporting rendering bugs

A good minimal reproducible report includes:

- minimal HTML/CSS/SVG input,
- expected vs actual result,
- generated output details when possible,
- package version/commit and PHP version.

## High-value contributions

- SVG edge-case fixtures,
- PDF compatibility fixtures,
- layout bug fixes,
- documentation improvements,
- tests for real-world signature templates,
- performance improvements backed by benchmark evidence.
