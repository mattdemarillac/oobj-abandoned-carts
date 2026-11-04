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
- Modules implement `OOBJ_AC_Module` (`register()` adds their hooks). `OOBJ_AC_Plugin::boot()` creates them and wires their dependencies. `oobj_abandoned_carts( OOBJ_AC_Store::class )` returns a module.
- `declare( strict_types=1 );` in every PHP file.
- Every change comes with tests, and coverage stays at 100% (once it gets there: see the README's Status).
