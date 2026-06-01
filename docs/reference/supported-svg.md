<!-- SPDX-FileCopyrightText: 2026 LibreSign -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Supported SVG

SVG support is intentionally scoped and deterministic.

## Supported usage pattern

- SVG is consumed through image source input (for example `<img src="/path/file.svg">`).
- `.svg` and `.svgz` extension detection is supported.
- Content-based SVG detection is also supported (`<svg` or XML with `<svg`).

## Supported drawable elements

- `path`
- `polygon`
- `polyline`
- `rect`
- `circle`
- `ellipse`
- `line`

Other elements (for example `text`, `image`) are skipped by SVG shape conversion.

## Supported path commands

- `M`, `m`
- `L`, `l`
- `H`, `h`
- `V`, `v`
- `C`, `c`
- `S`, `s`
- `Q`, `q`
- `T`, `t`
- `A`, `a`
- `Z`, `z`

Unsupported path commands raise an exception.

## Transforms

Supported transform operators:

- `matrix(...)`
- `translate(...)`
- `scale(...)`
- `rotate(...)` (including optional center)
- `skewX(...)`
- `skewY(...)`

Transforms are accumulated through ancestor chains.

## Color and paint behavior

Supported color sources include:

- presentation attributes (`fill`, `stroke`),
- inline style declarations,
- class-based style blocks (`.class { fill: ...; stroke: ...; }`).

Paint operations:

- fill only -> `f`
- stroke only -> `S`
- fill + stroke -> `B`

`stroke-width` is supported (style attribute takes precedence over presentation attribute).

## ViewBox and dimensions

- `viewBox` is supported and takes precedence over `width`/`height`.
- If no valid `viewBox` is present, positive `width` and `height` are required.
- Non-positive viewport dimensions are rejected.

## Unsupported or intentionally out-of-scope

- Full SVG text layout support.
- Filters, masks, gradients, patterns, and full SVG compositing model.
- Browser-equivalent SVG rendering semantics.

## Error behavior

Common error cases include:

- invalid SVG root/empty payload,
- malformed path data,
- unsupported path command,
- invalid or non-positive viewport definitions.

If this package helps your project generate reliable PDF signature appearances, consider starring the repository, contributing a fixture, or sponsoring maintenance.
