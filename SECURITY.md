# Security

## Reporting a vulnerability

Please report it privately: on this repo's **Security** tab, **Report a vulnerability** (GitHub's private
vulnerability reporting). Don't open a public issue. You'll get a reply within a week.

## Supported versions

Only the latest release is supported; fixes come as a new release, which sites update to like any plugin.

## Checks

Every push, pull request and Monday: Semgrep's PHP and JavaScript security rules, GitHub's CodeQL (JavaScript and
the workflows; CodeQL has no PHP), and `composer audit` / `npm audit` (what ships) for known-vulnerable dependencies
(`.github/workflows/security.yml`). Lint runs the WordPress Coding Standards' security sniffs (escaping, nonces, SQL
preparation). Dependabot proposes updates weekly.
