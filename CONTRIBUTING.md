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
