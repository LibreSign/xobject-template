<!-- SPDX-FileCopyrightText: 2026 LibreSign -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Public API

This page documents user-facing API intended for integration.

## Compiler entry point

- `LibreSign\XObjectTemplate\XObjectTemplateCompiler`
- `LibreSign\XObjectTemplate\Contract\XObjectTemplateCompilerInterface`

Primary method:

- `compile(CompileRequest $request): CompileResult`

## Request DTO

- `LibreSign\XObjectTemplate\Dto\CompileRequest`
  - `html: string`
  - `width: float = 240.0`
  - `height: float = 84.0`
  - `context: array<string, scalar> = []`

## Result DTO

- `LibreSign\XObjectTemplate\Dto\CompileResult`
  - `contentStream: string`
  - `resources: array<string, mixed>`
  - `bbox: array{0: float, 1: float, 2: float, 3: float}`
  - `metadata: array<string, mixed>`

## Integration helpers

- `LibreSign\XObjectTemplate\Integration\XObjectPayloadAdapter`
  - `toXObjectPayload(CompileResult $result): array{stream,resources,bbox}`
- `LibreSign\XObjectTemplate\Integration\XObjectPlacementCalculator`
  - `fromWidth(...)`, `fromHeight(...)`, `fromScale(...)`
- `LibreSign\XObjectTemplate\Integration\XObjectPlacement`
  - `toPdfCommand(string $alias): string`

## Preview/export helper

- `LibreSign\XObjectTemplate\Pdf\SinglePagePdfExporter`
  - `export(CompileResult $result): string`

## Exceptions users should handle

- `LibreSign\XObjectTemplate\Exception\UnsupportedSubsetException`
- `\InvalidArgumentException` from invalid placement/bounding box/image source/format scenarios.

For exact output semantics, see [Output contract](output-contract.md).
