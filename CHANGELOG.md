# Changelog

All notable changes to Config Pull will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- README restructured to follow the standard Drupal contrib README sections (Introduction, Requirements, Installation, Configuration, Usage, Security, Troubleshooting, Maintainers).
- README configuration examples corrected to match the settings keys actually read by the module.
- CONTRIBUTING and SECURITY revised for clarity and to reflect the published distribution.

### Fixed

- Documented Drush command count corrected (four commands: `fetch`, `status`, `diff`, `setup`).

## [0.1.1] - 2026-04-21

### Added

- `phpcs.xml.dist` documenting the Drupal coding standards exclusions used by the project.
- `SECURITY.md` describing supported versions and the vulnerability reporting process.
- `CONTRIBUTING.md` covering issue queue, coding standards, tests, and commit conventions.
- `.gitattributes` excluding tests, phpcs config, and contributor docs from release tarballs.
- README sections covering experimental status, install on both sides, support, and known limitations.

### Changed

- All four Drush commands (`config-pull:fetch`, `config-pull:status`, `config-pull:diff`, `config-pull:setup`) now have docblock descriptions, so `drush <command> --help` no longer warns about an undefined `description` key.
- `WizardPrompter` interface renamed to `WizardPrompterInterface` to match Drupal naming conventions.
- Long array declarations split to satisfy `Drupal.Files.LineLength`.
- `t()` calls in `config_pull.install` switched from single-quoted with escaped `\'` to double-quoted (Drupal coding standard).

### Fixed

- `drush <config-pull command> --help` no longer emits `[warning] Undefined array key "description"`.

## [0.1.0] - 2026-04-20

### Added

- HTTPS-signed remote configuration transfer between Drupal sites without database export.
- Four Drush commands:
  - `config-pull:status` lists items that are new, changed, or deleted on the remote relative to the local sync directory.
  - `config-pull:diff` shows field-level unified diffs, with `--show-values` for full content.
  - `config-pull:fetch` writes changed items to the local sync directory, with `--dry-run` to preview.
  - `config-pull:setup` is an interactive wizard that generates a shared secret and prints `settings.php` snippets for both sides.
- Glob-pattern filters (`--only`, `--exclude`) on `diff` and `fetch`.
- `--with-translations` flag to include config translation collections (server capability negotiated via handshake).
- Drush site alias support: pulling from `@prod` resolves the URI from the alias; the secret still comes from settings.
- Multisite: per-site secrets and remote definitions; `--uri` selects the active site.
- Server-side config split awareness: splits are applied before transfer.
- Capability handshake: client and server negotiate translations support, hash version, and other features.

### Security

- HMAC-SHA256 request signing with constant-time comparison.
- Replay protection via timestamp window and per-request nonce stored in the cache backend.
- IP allowlist support, trusted-proxy aware.
- Per-IP rate limiting.
- TLS enforcement (HTTP rejected unless `allow_insecure` is explicitly enabled).
- Server endpoint disabled by default; requires explicit `server_enabled => TRUE`.
- ANSI and control-byte stripping on all server-controlled strings rendered to the terminal.
- Typed remote exception classes (`RemoteAuthenticationException`, `RemoteRateLimitException`, `RemoteNetworkException`, `RemoteServerException`, `TransferInterruptedException`) for predictable client-side error handling.

### Known limitations

- Full export of very large config sets (3000+ items) can be slow. Workaround: use selective `--only` filters. Streaming export is planned for a future release.
- Single-secret model: rotating the shared secret requires a coordinated update on both sides.
- No web UI; the module is Drush-driven.

[Unreleased]: https://www.drupal.org/project/config_pull
[0.1.1]: https://www.drupal.org/project/config_pull/releases/0.1.1
[0.1.0]: https://www.drupal.org/project/config_pull/releases/0.1.0
