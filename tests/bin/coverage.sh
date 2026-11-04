#!/usr/bin/env bash
# Runs the PHPUnit suite with code coverage (composer test:coverage): a summary per class on the
# terminal, the HTML report in tests/coverage/html/ and Clover XML in tests/coverage/clover.xml. Fails
# unless every line is covered (tests/bin/coverage-check.php).
# Arguments are passed to PHPUnit.
#
# The litespeed container's PHP has no coverage driver, so PCOV is built once, in a throwaway container
# of the same image (the running stack isn't touched), into tests/coverage/pcov.so (git-ignored), and
# loaded only for this run, through tests/coverage/pcov.ini (PHP_INI_SCAN_DIR, which PHPUnit's
# process-isolated tests inherit).
set -euo pipefail

cd "$(dirname "$0")/../.."

pcov_version=1.0.12
theme=/var/www/vhosts/outofbody.local/html/wp-content/plugins/oobj-abandoned-carts

if [ ! -f tests/coverage/pcov.so ]; then
	image=$(docker inspect litespeed --format '{{.Config.Image}}')
	mkdir -p tests/coverage
	echo "Building PCOV ${pcov_version} for ${image}..." >&2
	docker run --rm --entrypoint sh -v "$PWD/tests/coverage:/out" "$image" -c "
		set -e
		export DEBIAN_FRONTEND=noninteractive
		php_dir=\$(dirname \"\$(dirname \"\$(readlink -f \"\$(command -v php)\")\")\")
		apt-get update -qq >/dev/null
		apt-get install -y -qq --no-install-recommends \$(basename \"\$php_dir\")-dev build-essential curl ca-certificates >/dev/null
		cd /tmp
		curl -fsSL https://pecl.php.net/get/pcov-${pcov_version}.tgz | tar xz
		cd pcov-${pcov_version}
		\"\$php_dir/bin/phpize\" >/dev/null
		./configure -q --with-php-config=\"\$php_dir/bin/php-config\" >/dev/null
		make -s -j\$(nproc) >/dev/null 2>&1
		cp modules/pcov.so /out/
	"
fi

cat > tests/coverage/pcov.ini <<INI
extension=${theme}/tests/coverage/pcov.so
pcov.enabled=1
pcov.directory=${theme}
memory_limit=2G
INI

# A leading ':' keeps PHP's own scan directory.
export PHP_INI_SCAN_DIR=":${theme}/tests/coverage"

rm -f tests/coverage/clover.xml
tests/bin/phpunit.sh \
	--coverage-text=php://stdout \
	--coverage-html tests/coverage/html \
	--coverage-clover tests/coverage/clover.xml \
	"$@"

# Fails below 100% of lines, Not with a filter: that runs only part of the suite.
# PHPUnit wrote the report in the site's copy (tests/bin/phpunit.sh): bring it here.
site_copy="${OOBJ_AC_SITE_COPY:-$HOME/Sites/oobj/ols-docker-env/sites/outofbody.local/html/wp-content/plugins/oobj-abandoned-carts}"
if [ -f "$site_copy/tests/coverage/clover.xml" ] && [ "$(cd "$site_copy" && pwd -P)" != "$(pwd -P)" ]; then
	cp "$site_copy/tests/coverage/clover.xml" tests/coverage/clover.xml
	rm -rf tests/coverage/html && cp -R "$site_copy/tests/coverage/html" tests/coverage/html
fi

case " $* " in
*" --filter"* | *" --group"* | *" --testsuite"*) ;;
*) php tests/bin/coverage-check.php tests/coverage/clover.xml ;;
esac
