<!-- SPDX-FileCopyrightText: 2026 LibreSign -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Limitations

`xobject-template` is intentionally scoped.

## Explicit non-goals

- Not a browser engine.
- Not a full PDF signer.
- Not a generic PDF editor.
- Not a complete HTML/CSS/SVG renderer.

## Rendering scope

- HTML/CSS/SVG support is intentionally limited to deterministic overlay/stamp workflows.
- Behavior is deterministic, but not browser-compatible.
- Unsupported subset inputs should be treated as out of scope, not as promised behavior.

## Integration expectation

Consumers should validate templates that matter to their workflow and keep fixture-based regression tests for critical appearance requirements.
