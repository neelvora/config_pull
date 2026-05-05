# Config Pull

Pull Drupal configuration from a remote site without downloading the database.

## Contents

- [Introduction](#introduction)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
- [Security](#security)
- [Troubleshooting](#troubleshooting)
- [Maintainers](#maintainers)

## Introduction

The standard Drupal config workflow assumes you `drush config:export` from
production, commit the YAML to git, deploy to staging, and run
`drush config:import`. That round trip works, but it forces a full database
copy into anywhere you want to inspect production configuration. For sites
with sensitive content, large media tables, or compliance constraints around
production data, copying the whole database to a developer laptop just to see
what changed in a view is a tax.

Config Pull lets a client site fetch only the configuration items it asks for,
signed and authenticated, over HTTPS. No database export. No content.
Configuration only.

The 0.1.x series is marked as `experimental` in `config_pull.info.yml`. Drupal
core's status report will warn that experimental modules are installed; this
is expected. The marker will be removed at 1.0.

## Requirements

- Drupal core 10.3 or later, or Drupal 11
- PHP 8.1 or later
- Drush 13 or later (the module is Drush-driven; there is no web UI)
- HTTPS on the server site (HTTP is rejected unless `allow_insecure` is
  explicitly enabled in settings, which is intended for loopback testing only)

This module has no other Drupal module dependencies. It composes well with
[Configuration Split](https://www.drupal.org/project/config_split) and
[Config Ignore](https://www.drupal.org/project/config_ignore) on the server
side.

## Installation

The module installs on **both** sites that participate in a pull: the *server*
site (the one publishing config) and the *client* site (the one fetching it).
Same package, different settings on each side.

```
composer require drupal/config_pull
drush en config_pull
```

Run the same two commands on each site. Then configure the relevant half (see
the next section) per site.

## Configuration

The fastest path is the interactive setup wizard:

```
drush config-pull:setup
```

It prompts for a remote name, the server URI, and either generates a 64-character
shared secret or accepts one you paste in. It prints two snippets, one for the
server's `settings.php` and one for the client's. Copy each into the
corresponding site's settings file. Then run the connectivity check it offers
at the end.

To configure by hand:

### Server site

Add to `settings.php` (or `settings.local.php` for per-environment overrides):

```php
$settings['config_pull'] = [
  'server_enabled' => TRUE,
  'secret' => '<run-drush-config-pull-setup-to-generate>',
  // Optional. Defaults shown.
  'allow_insecure' => FALSE,
  'rate_limit' => 10,
  'allowed_ips' => [],
  'redact' => [],
];
```

The shared secret must be at least 32 characters of high-entropy material.
The setup wizard generates 64 hex characters. Do not paste the placeholder
value above into a real settings file; the install requirements check will
reject any secret shorter than 32 characters.

### Client site

Add to `settings.php`:

```php
$settings['config_pull_remotes'] = [
  'prod' => [
    'uri' => 'https://www.example.com',
    'secret' => '<paste-the-server-secret-here>',
    // Optional. Defaults shown.
    'verify_ssl' => TRUE,
    'timeout' => 30,
  ],
];
```

You can configure multiple remotes (`prod`, `staging`, etc.) and pull from any
of them by name.

## Usage

### See what differs between the remote and your local

```
drush config-pull:status prod
```

Lists items that are new on the remote, changed on the remote, or deleted on
the remote (relative to your local sync directory).

### Show field-level diffs

```
drush config-pull:diff prod --show-values
```

Renders unified diffs for changed items. Pair with `--only` or `--exclude` to
filter.

### Pull changes

```
drush config-pull:fetch prod
```

Writes the changed items to your sync directory. After fetching, run
`drush config:import` to apply the changes to your active configuration.

Use `--dry-run` to preview without writing.

### Filter by glob pattern

```
drush config-pull:fetch prod --only='views.view.*'
drush config-pull:fetch prod --exclude='block.block.*'
```

### Pull with translations

```
drush config-pull:fetch prod --with-translations
```

Includes config translation collections. The server must support translations
(it does by default).

### Multisite

```
drush --uri=site2.example.com config-pull:fetch prod
```

Each site has its own settings, secrets, and remotes. The `--uri` flag selects
which one to operate on.

### Drush site aliases

If you have Drush site aliases defined for your remotes, you can reference
them by alias name:

```
drush config-pull:fetch @prod
```

The alias's URI is used automatically; the secret still comes from
`$settings['config_pull_remotes']`.

### What gets transferred

- Active configuration items (`system.site`, every view, every block, every
  content type, etc.)
- Config translation collections, when `--with-translations` is set and the
  server supports it
- Config splits, applied server-side before transfer (the server decides what
  to expose; the client receives the split-aware result)

What does not get transferred:

- Content (nodes, users, taxonomy terms, files, etc.)
- The database
- Anything in `config/install` or `config/optional` of any module (those are
  install-time defaults, not active configuration)
- Items deliberately excluded by config_ignore on the server (transparent to
  the client)

## Security

Config Pull exposes a small JSON-over-HTTPS API on the server site. Three
endpoints handle data: handshake, diff, and item/export. All three sit behind
a layered authentication chain.

### Authentication chain

Each request is verified in this order. Any failure short-circuits to 401
(authentication) or 403 (authorization):

1. **TLS check.** `Request::isSecure()` must return true unless
   `allow_insecure` is enabled.
2. **IP allowlist.** If `allowed_ips` is non-empty, the request's client IP
   must be in it. Trusted-proxy aware via Symfony's request handling.
3. **Rate limit.** Per remote IP. Configurable via `rate_limit`.
4. **HMAC signature.** Each request carries `X-Signature: hmac-sha256=...`
   over (verb + path + body + timestamp + nonce). The server recomputes with
   `hash_hmac` and compares with `hash_equals` (constant-time).
5. **Timestamp window.** The request `X-Timestamp` must fall within a tolerance
   window of the server clock to prevent replay of stale signed requests.
6. **Nonce.** The request `X-Nonce` must be unseen. Stored in the cache
   backend with TTL equal to the timestamp window. Prevents in-window replay.
7. **Server enabled.** `$settings['config_pull']['server_enabled']` must be
   true. Off by default.

### Threat model

The threat model assumes:

- The shared secret is at least 32 characters of high-entropy material (the
  setup wizard generates 64 hex characters).
- HTTPS terminates in front of the server endpoints.
- The server's web server enforces standard security headers and TLS
  configuration.

The module does not bypass Drupal's bootstrap, does not run code from the
request body, does not write to the filesystem on the server, and does not
expose configuration that the requesting role would not otherwise have access
to read via the standard config API.

### What an attacker can do with a stolen secret

With a valid secret, an attacker can read the same configuration items the
legitimate client could. They cannot:

- Write or delete any configuration on the server
- Read content (nodes, users, etc.)
- Execute code on the server
- Pivot to other sites in a multisite (each site has its own secret)

Operational mitigation: rotate the secret. Rotation requires updating both
sides; plan a coordinated change.

### Output sanitization

All command-line output that includes server-controlled strings (config names,
error messages from the remote) is run through a control-byte stripper before
printing to the terminal, which removes ANSI escape sequences and control
characters. This prevents a hostile server from injecting terminal escape
sequences into a developer's console.

Structured output formats (JSON via `--format=json`) are not sanitized. JSON
consumers are expected to handle their own output safely.

For reporting suspected vulnerabilities, see [SECURITY.md](SECURITY.md).

## Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| `Remote 'X' is not defined in settings.php. Available:` | `$settings['config_pull_remotes']` is unset on the client | Add the remote block under Configuration above; clear cache (`drush cr`) |
| `401 Unauthorized` | Secret mismatch, clock skew outside the timestamp window, or replayed request | Verify `secret` matches on both sides; check system clock on both servers; nonces are single-use |
| `403 Forbidden` | Source IP not in `allowed_ips`, or `server_enabled` is false | Add the client's egress IP to `$settings['config_pull']['allowed_ips']`, or set `server_enabled => TRUE` |
| `429 Too Many Requests` | Rate limit hit | Raise `rate_limit` or wait for the window to reset |
| `Server does not support translations` | Server is on an older module version | Upgrade the server to 0.1.0 or later |
| `Cannot connect to the remote` | Wrong URI, DNS failure, or network blocked | Verify URI scheme and host; try `curl -I` from the client host |
| Status report shows "experimental modules installed" | Expected for the 0.1.x series | Will be removed at 1.0 |
| Full export is slow at very large config counts (3000+ items) | Known limitation | Use selective `--only` filters; streaming export is planned for a future release |

### Known limitations

- No web UI. All operations are Drush commands.
- Single-secret model: rotating the shared secret requires a coordinated update
  on both sides.
- The server can only expose its *active* configuration. Items in
  `config/install` or `config/optional` are not transferred.
- Drupal 10.3 and 11.x are the only supported core versions.

## Maintainers

- See the project page on drupal.org: https://www.drupal.org/project/config_pull

Bugs, feature requests, and patches: please use the
[issue queue](https://www.drupal.org/project/issues/config_pull). Include
Drupal core version, PHP version, Drush version, and the exact command that
failed. Patches as merge requests, single commit preferred.

For security issues, see [SECURITY.md](SECURITY.md). Do not file public
issues for vulnerabilities.

## License

GPL-2.0-or-later. See [LICENSE.txt](LICENSE.txt).
