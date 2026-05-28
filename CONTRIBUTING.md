<!-- SPDX-FileCopyrightText: 2026 LibreSign -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Contributing

## Branch and PR flow

- Create a branch per scoped change.
- Open incremental PRs with objective descriptions.
- Keep PRs small and focused.

## Commit rules

- Conventional Commits.
- DCO sign-off is mandatory (`git commit -s`).

## Local quality checks

Run key checks before opening/updating PR:

- `composer lint`
- `composer run test:unit`
- `composer run test:coverage`
- `composer run deps:audit`

## Coverage policy

- CI generates Clover XML and HTML reports under `build/coverage/`
- Pull requests must keep **minimum line coverage at 95%**
- Coverage reports are stored temporarily in GitHub Actions ephemeral storage for post-PR analysis
