# Contributing to Config Pull

Thanks for considering a contribution. This document covers the basics for filing issues, submitting patches, and running the test suite locally.

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
- The full suite typically runs in under a minute. Please run it before submitting.

## Commit messages

- One commit per logical change.
- Subject line: 72 chars or less, area prefix preferred (`auth: ...`, `transfer: ...`, `docs: ...`, `tests: ...`).
- No multi-paragraph bodies unless the change genuinely needs them.

## Out of scope

These areas are unlikely to land without prior discussion in the issue queue:

- A web UI in the 0.1.x series. The module is Drush-driven by design for now.
- Refactors without a behavioral or measurable maintainability payoff.
- New runtime dependencies outside Drupal core's existing dependency closure.
- Support for Drupal cores older than 10.3.

If you have a use case that touches one of these, please open an issue first to discuss the approach.

## Roadmap

Near-term priorities are tracked in the project issue queue with the `roadmap` tag.
