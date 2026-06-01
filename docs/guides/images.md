<!-- SPDX-FileCopyrightText: 2026 LibreSign -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Images

Source example: `examples/images.php`

## Supported raster formats

Based on implementation and tests:

- PNG: supported.
- JPEG: supported.
- Other raster formats (for example GIF): not supported.

## Source resolution

Image sources are file paths from `<img src="...">`.

- During compile, the source path is carried into XObject resources.
- During export/embedding, the file is read from disk.

## Dimensions and resources

- `<img>` dimensions come from style width/height when provided.
- Missing/invalid image dimensions default to `32x32` in layout.
- Generated resources include XObject aliases (for example `Im0`, `Im1`) with `Source`, `Width`, and `Height` metadata.

## Error behavior and limitations

Typical failures include:

- source file missing/unreadable,
- unsupported mime type,
- invalid PNG/JPEG payload.

See [Errors and exceptions](../reference/errors.md) and [Supported SVG](../reference/supported-svg.md) for vector behavior.
