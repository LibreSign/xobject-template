<!-- SPDX-FileCopyrightText: 2026 LibreSign -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Examples

The examples are executable PHP files under `docs/source/examples/`.
Each one is executed and asserted by `tests/Integration/ExamplesOutputScenarioTest.php`,
which also validates the generated artifacts in `build/examples/`.

## Included examples

Expand an example to inspect the source inline.

??? example "basic-template.php — compile a simple template with a context variable"
	[Open on GitHub](https://github.com/LibreSign/xobject-template/blob/main/docs/source/examples/basic-template.php)

	```php
	--8<-- "examples/basic-template.php"
	```

??? example "preview-pdf.php — export a single-page PDF preview"
	[Open on GitHub](https://github.com/LibreSign/xobject-template/blob/main/docs/source/examples/preview-pdf.php)

	```php
	--8<-- "examples/preview-pdf.php"
	```

??? example "images.php — embed PNG and JPEG assets from base64 fixtures"
	[Open on GitHub](https://github.com/LibreSign/xobject-template/blob/main/docs/source/examples/images.php)

	```php
	--8<-- "examples/images.php"
	```

??? example "svg.php — render SVG content as an image source"
	[Open on GitHub](https://github.com/LibreSign/xobject-template/blob/main/docs/source/examples/svg.php)

	```php
	--8<-- "examples/svg.php"
	```

??? example "placement.php — demonstrate placement calculations"
	[Open on GitHub](https://github.com/LibreSign/xobject-template/blob/main/docs/source/examples/placement.php)

	```php
	--8<-- "examples/placement.php"
	```

??? example "interpolation.php — interpolate context variables in text"
	[Open on GitHub](https://github.com/LibreSign/xobject-template/blob/main/docs/source/examples/interpolation.php)

	```php
	--8<-- "examples/interpolation.php"
	```

## How to run them

```bash
composer run examples:test
```

## Output files

The integration scenario for examples writes generated artifacts to `build/examples/`
so the repository stays clean and the outputs remain disposable.

It also exports additional artifacts from reusable integration rendering scenarios to
`build/examples/integration-scenarios/`, so integration coverage doubles as practical examples.

Visible stamp integration scenarios are exported to `build/examples/visible-stamp/`
using the same shared scenario catalog and preview factory, including the GovBR-like
appearance as a first-class validated example.
