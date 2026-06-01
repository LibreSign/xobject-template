<!-- SPDX-FileCopyrightText: 2026 LibreSign -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Errors and exceptions

## Public exception to catch

- `LibreSign\XObjectTemplate\Exception\UnsupportedSubsetException`
  - Raised when HTML includes unsupported tags in the subset parser.

## Common invalid argument failures

`\InvalidArgumentException` can be raised by integration/export/image/SVG paths, for example:

- invalid `bbox` for export/placement,
- empty placement alias,
- image source missing or unreadable,
- unsupported image format,
- invalid SVG/viewBox/path payload.

## What users should do

- Validate input templates against [Supported HTML and CSS](supported-html-css.md).
- Validate SVG assets against [Supported SVG](supported-svg.md).
- Keep reproducible template and asset fixtures for failing cases.

## How to report a minimal reproducible issue

Please include:

- minimal HTML/CSS/SVG input,
- compile request dimensions,
- expected behavior,
- actual behavior,
- package version or commit hash,
- PHP version and runtime context.

See [Community / Contributing](../community/contributing.md) for reporting guidance.
