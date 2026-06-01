<!-- SPDX-FileCopyrightText: 2026 LibreSign -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Placement and scaling

Source example: `examples/placement.php`

## Downstream placement model

Compile once at a design size, then place the generated Form XObject in downstream PDFs.

In most workflows, proportional resizing should happen through placement scaling, not by recompiling HTML for each target size.

## Public helper classes

- `LibreSign\XObjectTemplate\Integration\XObjectPlacementCalculator`
- `LibreSign\XObjectTemplate\Integration\XObjectPlacement`

The example shows `fromWidth()`, `fromHeight()`, and `fromScale()` and emits a placement command string with `toPdfCommand()`.

## Why this is recommended

Uniform placement scaling keeps text, spacing, and images visually consistent and avoids introducing avoidable template variants.
