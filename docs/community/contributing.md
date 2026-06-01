<!-- SPDX-FileCopyrightText: 2026 LibreSign -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Contributing

Contributions are welcome. Keep quality high and changes scoped.

## Setup

Install dependencies and run checks from repository root.

## Core quality commands

- `composer lint`
- `composer test:unit`
- `composer test:integration`
- `composer docs:test`

## Documentation and examples

- Add/update runnable examples in `examples/`.
- Keep examples small and deterministic.
- Ensure docs snippets come from real examples.
- Run docs drift checks before opening a PR.

## Adding supported behavior

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
