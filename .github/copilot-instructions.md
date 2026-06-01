<!-- SPDX-FileCopyrightText: 2026 LibreSign -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Copilot Instructions

## Mandatory quality flow

1. Implement in small increments.
2. Run focused tests first, then full checks.
3. Ensure performance-sensitive paths stay within benchmark thresholds.

### Running benchmarks

Benchmarks use **PHPBench** for rigorous performance testing with statistical analysis.

```bash
# Run all benchmarks with aggregate statistics
composer benchmark:run

# Run with stricter settings (multiple revisions)
composer benchmark:run:ci

# Direct PHPBench invocation with custom options
vendor-bin/phpbench/vendor/phpbench/phpbench/bin/phpbench run --report=aggregate
```

PHPBench automatically:
- Runs warmup iterations (eliminates JIT/opcache startup noise)
- Executes multiple revisions for statistical confidence
- Reports mean, min, max, stdev, variance per benchmark

## Compliance and contribution

- DCO sign-off is mandatory for every commit.
- Keep REUSE/SPDX headers and AGPL compatibility.
- Respect allowed dependency licenses.

## Memory-as-Code policy

- Operational details belong to the memory repository, not this package repo.
- Keep this repository documentation minimal (`README.md` + mandatory governance files).
- Avoid doc sprawl.
