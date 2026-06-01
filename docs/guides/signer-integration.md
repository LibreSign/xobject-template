<!-- SPDX-FileCopyrightText: 2026 LibreSign -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# PDF signer integration

`xobject-template` does **not** sign PDFs.

It generates appearance payloads that downstream signers (or any PDF pipeline that can place Form XObjects) can consume.

## What downstream consumers typically use

- `contentStream`
- `resources`
- `bbox`
- optional placement data via integration helpers

## Typical flow

1. Compile template to Form XObject payload.
2. Place XObject in target page coordinates.
3. Let downstream signer handle signature cryptography and document-level signing workflow.

## LibreSign context

LibreSign is a flagship use case where this package focuses on deterministic appearance generation while LibreSign handles broader digital-signature workflow responsibilities.
