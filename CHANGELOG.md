# Changelog

All notable changes to Config Pull will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-04-20

First public release. Production-ready for the documented surface.

### Added

- HTTPS-signed remote configuration transfer between Drupal sites without database export.
- Three Drush commands:
  - `config-pull:status` — list new, changed, and deleted items relative to a remote.
  - `config-pull:diff` — show field-level unified diffs, with `--show-values` for full content.
  - `config-pull:fetch` — write changed items to the local sync directory, with `--dry-run`.
  - `config-pull:setup` — interactive wizard that generates a shared secret and prints `settings.php` snippets for both sides.
- Glob-pattern filters (`--only`, `--exclude`) on diff and fetch.
- `--with-translations` flag to include config translation collections (server capability negotiated via handshake).
- Drush site alias support: pulling from `@prod` resolves the URI from the alias, secret from settings.
- Multisite: per-site secrets and remote definitions; `--uri` selects the active site.
- Server-side config split awareness: splits are applied before transfer.
- Capability handshake: client and server negotiate translations support, hash version, and other features.
- Capped export sizes (5000 items, 50 MB by default; configurable).

### Security

- HMAC-SHA256 request signing with constant-time comparison.
- Replay protection via timestamp window (±5 min) and per-request nonce stored in cache.
- IP allowlist support, trusted-proxy aware.
- Per-IP rate limiting (60/min default).
- TLS enforcement (HTTP rejected unless `allow_insecure` is explicitly enabled).
- Server endpoint disabled by default; requires explicit `server_enabled => TRUE`.
- ANSI/control-byte stripping on all server-controlled strings rendered to the terminal.
- Typed remote exception classes (`RemoteAuthenticationException`, `RemoteRateLimitException`, `RemoteNetworkException`, `RemoteServerException`, `TransferInterruptedException`) for predictable client-side error handling.

### Performance

- 209 tests, 522 assertions, all green on Drupal 11.3.7 / PHP 8.3 / PHPUnit 11.5.
- Cold diff at 177 items: ~11 ms.
- Full export at 177 items: ~600 ms.
- See `local-notes/performance-benchmarks.md` for the full SLA reconciliation.

### Known limitations

- Full export at 3000+ items exceeds the 5-second SLA target. Workaround: use selective `--only` filters. Streaming gzip is planned for 0.2.0.
- Single-secret model (no key rotation grace period). Operational rotation requires coordinated update of both sides.
- No web UI; module is Drush-driven.
- Real second-site cross-host TLS verification deferred to the drupal.org GitLab CI cycle (loopback HTTPS verified locally).

[0.1.0]: https://www.drupal.org/project/config_pull/releases/0.1.0
