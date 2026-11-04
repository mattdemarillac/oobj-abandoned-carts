# Development

## Setup

```sh
composer install
```

Requires PHP 8.1+ and Composer. The tests need a WordPress install with WooCommerce and a MySQL or MariaDB database for the test suite.

## Commands

| Command | |
|---|---|
| `composer test` | PHPUnit (the WordPress test suite, with WooCommerce). Arguments go to PHPUnit, e.g. `composer test -- --filter SavedCarts`. |
| `composer test:js` (or `npm test`) | Jest tests of the scripts in `assets/` (`tests/js/`), in jsdom, with coverage; fails below 100% (branches, functions, lines, statements). Run `npm install` first. |
| `composer test:coverage` | PHPUnit with line coverage (PCOV): a summary per class, an HTML report in `tests/coverage/html/`, and a failure below 100% of lines, listing the lines not covered. |
| `composer lint` | PHP_CodeSniffer with the WordPress Coding Standards (`phpcs.xml.dist`). |
| `composer lint:fix` | Fixes what PHP_CodeSniffer can. |

## How the tests run

- `tests/bootstrap.php` loads the WordPress test suite (`wp-phpunit/wp-phpunit`), WooCommerce and the plugin, and installs WooCommerce's tables.
- `tests/wp-tests-config.php` reads the database from `WP_TESTS_DB_NAME`, `WP_TESTS_DB_USER`, `WP_TESTS_DB_PASSWORD` and `WP_TESTS_DB_HOST`. **The suite drops and recreates every table in that database on each run: use a database only the tests use.**
- WordPress itself is found relative to the plugin, as `wp-content/plugins/<plugin>/` (or set `WP_TESTS_ABSPATH` to the WordPress folder). `WP_TESTS_DB_HOST` defaults to `mysql`.
- `tests/class-oobj-ac-testcase.php` is the base test case: products, a fresh cart and session, orders, and direct access to the tables.
- Code that runs while the plugin loads (its file, `boot()`, the modules' `register()`) runs before PHPUnit starts measuring. The bootstrap records it with PCOV, and `OOBJ_AC_Bootstrap_Coverage` (a PHPUnit listener) adds it to the report.

## The current scripts and their assumptions

`tests/bin/phpunit.sh` and `tests/bin/coverage.sh` were written for one Docker-based development site and assume:

- a container named `litespeed` that runs PHP, with the plugin at `/var/www/vhosts/outofbody.local/html/wp-content/plugins/oobj-abandoned-carts`;
- a MariaDB container named `ols-docker-env-mysql-1`, in which they create the `oobj_abandoned_carts_tests` database;
- the site's copy of the plugin at `~/Sites/oobj/ols-docker-env/sites/outofbody.local/html/wp-content/plugins/oobj-abandoned-carts` (`OOBJ_AC_SITE_COPY` overrides it), which `phpunit.sh` syncs from the repository before each run;
- PCOV built once for that container's PHP, into `tests/coverage/pcov.so`.

**Before a public release**, these should become generic: run PHPUnit directly when PHP and the database are available, take the container, paths and database from environment variables, and leave Docker to a documented option. A CI workflow (GitHub Actions with a MariaDB service and `setup-php` with PCOV) needs no Docker at all.

## Conventions

- Classes are `OOBJ_AC_Foo_Bar` in `inc/class-oobj-ac-foo-bar.php`, loaded by the autoloader in the plugin file. Interfaces are in `inc/interface-oobj-ac-*.php`.
- **Optional integrations with other services are adapters:** the class in `inc/adapters/`, its script in `assets/adapters/`. The core never names a service: it works through an interface (`OOBJ_AC_Bot_Check`) or an event (`oobj-ac:saved`), and every adapter can be turned off with a filter. Currently: `OOBJ_AC_Turnstile` (bot check) and `OOBJ_AC_GA4` (analytics).
- Modules implement `OOBJ_AC_Module` (`register()` adds their hooks). `OOBJ_AC_Plugin::boot()` creates them and wires their dependencies. `oobj_abandoned_carts( OOBJ_AC_Store::class )` returns a module.
- `declare( strict_types=1 );` in every PHP file.
- Every change comes with tests, and coverage stays at 100%: PHPUnit for the PHP, Jest for the scripts. CI won't release otherwise.

## CI and releases

`.github/workflows/release.yml` runs on every push to `main`:

1. **lint:** `composer lint`.
2. **test-js:** `npm test`, the scripts' Jest tests, failing below 100% coverage.
3. **test-php:** PHPUnit with coverage against WordPress and WooCommerce (installed by `tests/bin/ci-wordpress.sh`, versions in that script) and a MariaDB service, then the 100% line coverage check.
4. **release**, only when all three pass: checks that the header's `Version` and `OOBJ_ABANDONED_CARTS_VERSION` match, builds `oobj-abandoned-carts-v<Version>.zip` (the plugin folder with `assets/`, `inc/`, the plugin file, `uninstall.php` and `LICENSE`), and publishes it as release `v<Version>`, marked latest.

Until `Version` changes, each push to `main` moves the `v<Version>` tag to the new commit and replaces the release's zip. **To release a new version, bump both `Version` in the plugin header and `OOBJ_ABANDONED_CARTS_VERSION`.**

Sites update through **Dashboard → Updates** ([OOBJ_AC_Updates](../inc/class-oobj-ac-updates.php)): the header's `Update URI` names the repo, and the update is the latest release's zip. The repo is public, so sites need no token. A private copy (a fork, say) needs a fine-grained token with read access to its contents in the site's `wp-config.php`: `define( 'OOBJ_AC_GITHUB_TOKEN', '…' );`. Without a token GitHub allows 60 API requests an hour per server; WordPress checks twice a day. Updates are off on a site whose environment type is `local`, whose plugin folder is a working copy.
