# Security Policy

## Supported versions

| Version | Supported |
|---------|-----------|
| 0.1.1   | Yes       |
| < 0.1.1 | No        |

The 0.1.x branch receives security fixes until 1.0.0 is released. After 1.0, the policy will follow the Drupal contrib norm: the latest minor of the current major receives security fixes; the previous major receives security fixes for a published deprecation window.

## Reporting a vulnerability

Please do not file public issues for security vulnerabilities.

Follow the Drupal Security Team disclosure process at https://www.drupal.org/security-team/report-issue. If a report cannot be filed through that channel, contact the maintainers listed on the project page on drupal.org.

When reporting, please include:

- Drupal core version, PHP version, Drush version
- Module version (`drush pm:list --filter=config_pull`)
- A minimal reproduction or proof of concept
- The impact you observed and the impact you believe is achievable

You will receive an acknowledgement within seven days. Confirmed vulnerabilities will be fixed in a coordinated release; you will be credited in the advisory unless you request otherwise.

## Out of scope

- Issues that require an attacker to already hold a valid shared secret (the threat model assumes the secret is protected; with a valid secret, an attacker can read the same configuration the legitimate client could)
- Issues that require attacker control of `settings.php` (the trust boundary is the file system; if `settings.php` is writable by an attacker, the entire site is compromised)
- Denial of service via legitimate request volume below the configured rate limit (raise `rate_limit` if your operational profile requires it)
- Configuration disclosure when `allow_insecure` is true (this flag is documented as for-loopback-testing-only)
