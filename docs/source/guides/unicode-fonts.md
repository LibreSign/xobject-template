<!-- SPDX-FileCopyrightText: 2026 LibreSign -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Unicode text in PDF XObjects

The compiler preserves Unicode names using embedded TrueType fonts, a Type 0/CIDFontType2 font resource, and a `ToUnicode` map. Plain single-byte PDF Type 1 fonts do not cover Arabic, Hebrew, CJK, and many other scripts.

## Configure fonts

```php
use LibreSign\XObjectTemplate\Dto\CompileRequest;
use LibreSign\XObjectTemplate\Pdf\TemplateDocumentBuilder;
use LibreSign\XObjectTemplate\XObjectTemplateCompiler;

$builder = (new TemplateDocumentBuilder())->withUnicodeFontFiles([
    '/path/to/embeddable/DejaVuSans.ttf',
    '/path/to/embeddable/CJK-font.ttf',
]);
$compiler = new XObjectTemplateCompiler(documentBuilder: $builder);
$result = $compiler->compile(new CompileRequest(
    html: '<div>Signed by محمد</div>',
    width: 400,
    height: 90,
));
```

Only supply trusted, valid `.ttf` files with embedding rights. Fonts are loaded from explicitly configured paths, **not** discovered from arbitrary template input. A selected font currently needs to cover every Unicode scalar in the same text line; font fallback does not yet splice a single line across multiple fonts. Missing glyph coverage raises an exception rather than silently substituting a character.

### Complex scripts

HarfBuzz handles OpenType glyph substitution/positioning; FriBidi provides paragraph directions and visual ordering. Deployments that use Arabic, Hebrew or combining marks must provide the `hb-shape` and `fribidi` executables on `PATH`. For Debian/Ubuntu these are packaged as `libharfbuzz-bin` and `libfribidi-bin`. No native tools are required for the simpler direct-glyph path. A missing or failing native tool produces an error, never a silently incorrect PDF.

Both tools are executed with argument arrays, a fixed timeout and limited input. The shaping font is a temporary private file, deleted in a `finally` block. Inputs are decoded and validated as Unicode scalars.

For shaped text, PDF appearance operators use HarfBuzz's exact glyph IDs and positioning with a logical Unicode `ActualText` span. Different PDF extractors can report bidirectional text in visual order despite `ActualText`; test extraction with more than one independent reader when interoperability matters.

### Signed PDF integration

For signature appearance XObjects, the resource dictionary must be materialized **in the destination PDF document**, not simply embedded as PHP arrays. The `SignerPhpCoreXObjectResourceBuilder` adapter supplies the resources-builder closure to the compatible `SignerPHP/pdf-core` `AppearanceXObject`. This depends on the optional resource-builder API introduced in [SignerPHP/pdf-core PR #5](https://github.com/SignerPHP/pdf-core/pull/5). A consumer must first use a `pdf-core` version containing that API; older releases cannot process these indirect resources.

Test signature appearance output cryptographically (CMS/ByteRange), structurally (font file, descendant font and `ToUnicode`) and visually. Passing just one of these checks is insufficient.

### Limits

* Complete font files are currently embedded; glyph subsetting is not implemented. This increases signed-PDF size. FontTools' `pyftsubset --retain-gids` is a possible **future** optimization, but needs reproducible tests for CID/GID stability and licensing before adoption.
* The library handles the scripts exercised by its integration matrix; it does not claim full OpenType coverage for every language or arbitrary mixtures requiring different fonts within a single line.
* Complex shaping requires the external binaries at runtime and appropriate fonts in the deployment environment.
