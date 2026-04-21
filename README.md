# Config Pull

Pull Drupal configuration from a remote site without downloading the database.

## Why this module exists

Drupal's stock config workflow assumes you `drush config:export` from production, commit the YAML to git, deploy to staging, and run `drush config:import`. That round trip works, but it forces the full database into anywhere you want to inspect production config. For organizations with sensitive content, large media tables, or compliance constraints around production data, copying the whole database to a developer laptop just to see what changed in a view is a tax.

Config Pull lets a client site fetch only the configuration items it asks for, signed and authenticated, over HTTPS. No database export. No content. Configuration only.

## Status

**0.1.0**, first public release. Production-ready for the documented surface (handshake, diff, fetch, transfer, dry-run, selective, with-translations, multisite). See `CHANGELOG.md`.

The module ships marked as `experimental` in `config_pull.info.yml` for the 0.1.x series. Drupal core will print a warning on the status report about experimental modules being installed; this is expected. The marker will be removed when the module reaches 1.0 on drupal.org.

## Audience

This README has two halves. The first half is for developers and site builders adopting the module. The second half (after the divider) is for security reviewers evaluating whether to allow this module in a production environment.

---

# Part 1: For developers and site builders

## Requirements

- Drupal core ^10.3 or ^11
- PHP >= 8.1
- Drush ^13 (the module is Drush-driven; there is no UI in 0.1.0)
- HTTPS on the server site (HTTP is blocked unless you explicitly opt in via `allow_insecure`, which you should not in production)

## Install

The module installs on **both** sites that participate in a pull: the *server* site (the one publishing config) and the *client* site (the one fetching it). Same package, different settings on each side.

```bash
composer require drupal/config_pull
drush en config_pull
```

Run the same two commands on each site. Then configure the relevant half (server-side keys on the server, remote definitions on the client) per the sections below.

## Five-minute setup

The fastest path is the interactive wizard:

```bash
drush config-pull:setup
```

It will ask for a remote name, the server URI, and either generate a shared secret or accept one you paste in. It prints two snippets, one for the server's `settings.php` and one for the client's. Copy each into the corresponding site's settings file. Then run the connectivity check it offers at the end.

If you prefer to configure by hand, see the next section.

## Manual configuration

### Server site

Add to `settings.php` (or `settings.local.php` for per-environment overrides):

```php
$settings['config_pull'] = [
  'server_enabled' => TRUE,
  'secret' => 'your-shared-secret-at-least-32-chars-long',
  // Optional. Defaults shown.
  'allow_insecure' => FALSE,
  'rate_limit_per_minute' => 60,
  'allowed_ips' => [],
  'max_export_items' => 5000,
  'max_export_bytes' => 50 * 1024 * 1024,
];
```

### Client site

Add to `settings.php`:

```php
$settings['config_pull_remotes'] = [
  'prod' => [
    'uri' => 'https://www.example.com',
    'secret' => 'your-shared-secret-at-least-32-chars-long',
    // Optional. Defaults shown.
    'verify_ssl' => TRUE,
    'timeout' => 30,
  ],
];
```

You can configure multiple remotes (`prod`, `staging`, etc.) and pull from any of them by name.

## Daily use

### See what differs between the remote and your local

```bash
drush config-pull:status prod
```

Lists items that are new on the remote, changed on the remote, or deleted on the remote (relative to your local sync directory).

### Show field-level diffs

```bash
drush config-pull:diff prod --show-values
```

Renders unified diffs for changed items. Pair with `--only` or `--exclude` to filter.

### Pull changes

```bash
drush config-pull:fetch prod
```

Writes the changed items to your sync directory. After fetching, run `drush config:import` if you want to apply the changes to your active configuration.

Use `--dry-run` to preview without writing.

### Filter by glob pattern

```bash
drush config-pull:fetch prod --only='views.view.*'
drush config-pull:fetch prod --exclude='block.block.*'
```

### Pull with translations

```bash
drush config-pull:fetch prod --with-translations
```

Includes config translation collections. The server must support translations (it does by default).

### Multisite

```bash
drush --uri=site2.example.com config-pull:fetch prod
```

Each site has its own settings, secrets, and remotes. The `--uri` flag selects which one to operate on.

### Site aliases

If you have Drush site aliases defined for your remotes, you can reference them by alias name:

```bash
drush config-pull:fetch @prod
```

The alias's URI is used automatically; the secret still comes from `$settings['config_pull_remotes']`.

## What gets transferred

- Active configuration items (`system.site`, every view, every block, every content type, etc.)
- Config translation collections, when `--with-translations` is set and the server supports it
- Config splits, applied server-side before transfer (the server decides what to expose; the client receives the split-aware result)

What does not get transferred:

- Content (nodes, users, taxonomy terms, files, etc.)
- The database
- Anything in `config/install` or `config/optional` of any module (those are install-time defaults, not active config)
- Items deliberately excluded by config_ignore on the server (transparent to the client)

## Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| `Remote 'X' is not defined in settings.php. Available:` | `$settings['config_pull_remotes']` is unset on the client | Add the remote block under Manual configuration above; clear cache (`drush cr`) |
| `401 Unauthorized` | Secret mismatch, clock skew > 5 min, or replayed request | Verify `secret` matches on both sides; check `date` on both servers; nonces are single-use |
| `403 Forbidden` | Source IP not in `allowed_ips`, or `server_enabled` is false | Add the client's egress IP to `$settings['config_pull']['allowed_ips']`, or set `server_enabled => TRUE` |
| `429 Too Many Requests` | Rate limit hit | Increase `rate_limit_per_minute` or wait one minute |
| `Server does not support translations` | Server is on an older module version | Upgrade the server to >= 0.1.0 |
| `Cannot connect to the remote` | Wrong URI, DNS failure, or network blocked | Verify URI scheme + host, try `curl -I` from the client host |
| Status report shows "experimental modules installed" | Expected for 0.1.x | Will be removed at 1.0; safe to ignore in the meantime |
| Full export is slow at 3000+ items | Known limitation, see `CHANGELOG.md` | Use selective `--only` filters; streaming gzip is planned for 0.2.0 |

## Support

- **Bugs and feature requests:** the project issue queue at https://www.drupal.org/project/issues/config_pull (preferred). Please include Drupal core version, PHP version, Drush version, and the exact command that failed.
- **Security issues:** do not file public issues. See the section at the end of this README.
- **Questions:** the `#contribute` and `#config-management` channels on the Drupal Slack are good first stops; tag with `config_pull` so the maintainer sees it.

## Known limitations (0.1.0)

- No web UI. All operations are Drush commands. A UI is not on the 1.0 roadmap.
- Single-secret model: rotating the shared secret requires a coordinated update on both sides. A grace-period two-secret model is planned post-1.0.
- Full export is read into memory before streaming. Bounded by `max_export_bytes` (default 50 MB). Streaming gzip is planned for 0.2.0 and will lift this constraint.
- The server can only expose its *active* configuration. Items in `config/install` or `config/optional` are not transferred.
- Drupal 10.3+ and 11.x are the only supported core versions. There are no plans to support older cores.

## Performance

On a Drupal 11 install with 177 config items (single Lando container, no network latency):

- Handshake: ~30ms
- Cold diff: ~11ms
- Warm diff (304 short-circuit): ~12ms
- Full export (tar.gz): ~600ms
- Selective export of 50 items: ~66ms

Extrapolated to 3000 items: cold diff ~190ms, full export ~10s. Diff scales well; full export will be addressed by streaming gzip in 0.2.0.

---

# Part 2: For security reviewers

This section assumes you are evaluating whether Config Pull is safe to deploy on a site that handles sensitive data. If that is not your role, skip this part.

## Threat model

Config Pull exposes a small JSON-over-HTTPS API on the server site. Three endpoints handle data: handshake, diff, and item/export. All three sit behind a layered authentication chain. The threat model assumes:

- The shared secret is at least 32 characters of high-entropy material (the wizard generates 64 hex chars)
- HTTPS is in front of the server endpoints (HTTP is rejected unless `allow_insecure` is true, which is documented as for-loopback-testing-only)
- The server's web server enforces standard security headers and TLS configuration

The module itself does not weaken these assumptions. It does not bypass Drupal's bootstrap, does not run code from the request body, does not write to the filesystem on the server, and does not expose configuration that the requesting role would not otherwise have access to read via the standard config API.

## Authentication chain

Each request is verified in this order. Any failure short-circuits to 401 (auth) or 403 (authz):

1. **TLS check.** `Request::isSecure()` must return true unless `allow_insecure` is set.
2. **IP allowlist.** If `allowed_ips` is non-empty, the request's client IP must be in it. Trusted-proxy-aware via Symfony's request handling.
3. **Rate limit.** Token-bucket per remote IP, default 60/minute. Configurable via `rate_limit_per_minute`.
4. **HMAC signature.** Each request carries `X-Signature: hmac-sha256=...` over (verb + path + body + timestamp + nonce). The server recomputes with `hash_hmac` and compares with `hash_equals` (constant-time).
5. **Timestamp window.** Request `X-Timestamp` must be within ±5 minutes of server clock to prevent replay of stale signed requests.
6. **Nonce.** Request `X-Nonce` must be unseen. Stored in the cache backend with TTL = window. Prevents in-window replay.
7. **Server enabled.** `$settings['config_pull']['server_enabled']` must be true. Off by default.

All checks are unit-tested in `AuthenticationServiceTest` (~25 tests) and integration-tested in `SecurityControlsKernelTest` (10 tests).

## What an attacker can do with a stolen secret

With a valid secret, an attacker can read the same configuration items the legitimate client could. They cannot:

- Write or delete any configuration on the server
- Read content (nodes, users, etc.)
- Execute code on the server
- Pivot to other sites in a multisite (each site has its own secret)

Operational mitigation: rotate the secret. There is no key-id mechanism in 0.1.0; rotation requires updating both sides simultaneously. A grace-period two-secret model is on the post-1.0 roadmap.

## Resource exhaustion controls

| Control | Default | Setting |
|---------|---------|---------|
| Rate limit | 60 req/min per IP | `rate_limit_per_minute` |
| Max items per export | 5000 | `max_export_items` |
| Max bytes per export | 50 MB | `max_export_bytes` |
| Per-item read | streaming via `ConfigStorageInterface::read()` | not configurable |

Server-side memory is bounded by the largest single config item, not the total export size, because items are read one at a time.

## Output sanitization

All command-line output that includes server-controlled strings (config names, error messages from the remote) is run through `stripControlBytes()`, which removes ANSI escape sequences and control bytes before printing to the terminal. This prevents a hostile server from injecting terminal escape sequences into a developer's console.

Scope note: structured output formats (JSON via `--format=json`) are not sanitized. JSON consumers are expected to handle their own output safely.

## Known residual risks

| Risk | Mitigation | Status |
|------|-----------|--------|
| Real second-site cross-host TLS not exercised in CI | Loopback HTTPS verified; drupal.org GitLab CI will exercise cross-container | Deferred to drupal.org submission cycle |
| Full export at 3000+ items exceeds 5s SLA | Use selective filters; streaming gzip planned | Known miss for 0.1.0; roadmapped for 0.2.0 |
| Single-secret model (no key rotation grace period) | Operational rotation requires coordinated update | Post-1.0 roadmap |

For the full security review checklist with line-item evidence, see `local-notes/security-review.md` in the project repository (not shipped with the module).

## License

GPL-2.0-or-later. See `LICENSE.txt`.

## Reporting security issues

Please do not file public issues for security vulnerabilities. Email the maintainer directly per the security advisory policy on drupal.org.
