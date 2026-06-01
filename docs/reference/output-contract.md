<!-- SPDX-FileCopyrightText: 2026 LibreSign -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Output contract

`CompileResult` is the stable output boundary for integration.

## Fields

| Field | Type | Meaning |
|---|---|---|
| `contentStream` | `string` | PDF operators intended for a Form XObject stream body. |
| `resources` | `array<string,mixed>` | Resource dictionary data (fonts + referenced image XObjects). |
| `bbox` | `[float,float,float,float]` | Bounding box `[x1,y1,x2,y2]` used for placement/export. |
| `metadata` | `array<string,mixed>` | Diagnostics such as `render_ms`, `line_count`, `image_count`, `node_count`. |

## Resource shape used by helpers

- Fonts are keyed aliases (for example `F1`..`F6`) with PDF font dictionaries.
- Image resources are keyed aliases (for example `Im0`) with at least source/size metadata.

## What downstream consumers should rely on

- `contentStream` + `resources` + `bbox` as the core Form XObject payload contract.
- `bbox` defines base geometry for placement scaling.

## What is intentionally not guaranteed

- Browser-equivalent rendering behavior.
- Full HTML/CSS/SVG compatibility.
- Stable internals of parser/layout implementation classes.

For transport-neutral payload arrays, see `XObjectPayloadAdapter` in [Public API](public-api.md).
