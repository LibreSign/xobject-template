<!-- SPDX-FileCopyrightText: 2026 LibreSign -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Documentation folder layout

This folder is intentionally split to isolate source content from generated output.

## Structure

- `docs/source/`: documentation source files maintained by contributors and intended for publication.
- `docs/site/`: generated static site output (build artifact, not versioned).

The public entry page is `docs/index.md`.

## Rules

- Keep hand-written documentation in `docs/source/`.
- Never commit generated files under `docs/site/`.
- Build outputs in root `site/` are also ignored to reduce accidental commits.

If a docs tool is reintroduced in the future, configure it to read from `docs/source/` and write to `docs/site/`.
