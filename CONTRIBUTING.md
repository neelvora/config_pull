# Contributing to Config Pull

Thank you for considering a contribution. This document covers the basics; see `local-notes/` in the maintainer's working tree for deeper context (not shipped).

## Where to file things

- **Bugs and feature requests:** https://www.drupal.org/project/issues/config_pull
- **Security issues:** see `SECURITY.md`. Do not file in the public queue.
- **Patches:** attach a merge request to the relevant issue. Single-commit MRs preferred.

## Before you open an issue

- Search the issue queue first; near-duplicates are common.
- Include Drupal core version, PHP version, Drush version, and the exact command that failed.
- For "this should work differently" requests, describe the use case, not just the desired behavior.

## Development setup

1. Clone the project: `git clone https://git.drupalcode.org/project/config_pull`
2. Place it under a Drupal site at `web/modules/contrib/config_pull` (or use a path repository).
3. Install dev deps from your Drupal root: `composer require --dev drupal/coder phpstan/phpstan mglaman/phpstan-drupal squizlabs/php_codesniffer`
4. Run the test suite: `vendor/bin/phpunit web/modules/contrib/config_pull`

## Coding standards

- Drupal coding standards via `phpcs` are required. The module ships `phpcs.xml.dist` documenting the sniffs we exclude and why.
- `phpstan analyse --level=1 src/` must pass.
- Run both before submitting a patch:

```bash
cd web/modules/contrib/config_pull
../../../../vendor/bin/phpcs
../../../../vendor/bin/phpstan analyse --level=1 src/
```

## Tests

- All non-trivial changes need test coverage. Bug fixes need a regression test.
- Unit tests live in `tests/src/Unit/`, kernel tests in `tests/src/Kernel/`.
- The full suite runs in under a minute on a single Lando container; please run it before submitting.

## Commit messages

- One commit per logical change.
- Subject line: 72 chars or less, area prefix preferred (`auth: ...`, `transfer: ...`, `docs: ...`, `tests: ...`).
- No multi-paragraph bodies unless the change genuinely needs them.

## What we will probably not accept

- New features that add a UI in 0.1.x (no UI is planned before 1.0).
- Refactors without a behavioral payoff.
- Dependencies on packages not already in Drupal core's dependency closure, unless there is a strong case.
- Support for Drupal cores older than 10.3.

## Roadmap

The maintainer's near-term priorities are tracked in the project issue queue with the `roadmap` tag. Streaming gzip for full export (the headline 0.2.0 item) is the only feature we have publicly committed to before 1.0.
