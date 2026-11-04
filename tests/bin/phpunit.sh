#!/usr/bin/env bash
# Runs the PHPUnit suite (composer test). The tests need the site's WordPress (and WooCommerce), so on the
# host this runs itself inside the litespeed container of the local Docker stack. Arguments are passed to
# PHPUnit, e.g. composer test -- --filter Cutout. PHP_INI_SCAN_DIR is passed on into the container (coverage.sh
# loads PCOV with it).
set -euo pipefail

plugin=/var/www/vhosts/outofbody.local/html/wp-content/plugins/oobj-abandoned-carts

# Inside the container: run PHPUnit.
if [ "$(pwd -P)" = "$plugin" ]; then
	exec php vendor/bin/phpunit "$@"
fi

# The site runs the copy in its plugins folder: bring it up to date with this repo first (the repo isn't mounted
# there, unlike the theme). OOBJ_AC_SITE_COPY overrides where that copy is.
site_copy="${OOBJ_AC_SITE_COPY:-$HOME/Sites/oobj/ols-docker-env/sites/outofbody.local/html/wp-content/plugins/oobj-abandoned-carts}"
if [ -d "$site_copy" ] && [ "$(cd "$site_copy" && pwd -P)" != "$(pwd -P)" ]; then
	rsync -a --delete --exclude .git --exclude node_modules --exclude tests/coverage/html --exclude tests/coverage/clover.xml ./ "$site_copy/"
fi

mysql=ols-docker-env-mysql-1

if ! docker ps --format '{{.Names}}' 2>/dev/null | grep -qx litespeed; then
	echo "The litespeed container isn't running. Start the stack (bin/start.sh in the oobj repo)." >&2
	exit 1
fi

if [ ! -d vendor ]; then
	echo "Missing vendor/. Run: composer install" >&2
	exit 1
fi

# Test-only database, separate from the theme's (oob_tests) so both suites can run at once. The WordPress
# test suite drops and reinstalls its tables on every run.
docker exec "$mysql" sh -c 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" -e "CREATE DATABASE IF NOT EXISTS oobj_abandoned_carts_tests; GRANT ALL PRIVILEGES ON oobj_abandoned_carts_tests.* TO \`$MYSQL_USER\`@\`%\`;"'

db_user=$(docker exec "$mysql" printenv MYSQL_USER)
db_password=$(docker exec "$mysql" printenv MYSQL_PASSWORD)

exec docker exec -w "$plugin" \
	${PHP_INI_SCAN_DIR+-e PHP_INI_SCAN_DIR="$PHP_INI_SCAN_DIR"} \
	-e WP_TESTS_DB_USER="$db_user" \
	-e WP_TESTS_DB_PASSWORD="$db_password" \
	litespeed php vendor/bin/phpunit "$@"
