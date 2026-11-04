#!/usr/bin/env bash
# Installs WordPress and WooCommerce around this plugin, for running the PHPUnit suite outside a development site
# (GitHub Actions). The plugin must already be at <wordpress>/wp-content/plugins/<folder>: the folder four levels up
# from tests/ is WordPress's root, as tests/wp-tests-config.php expects.
#
# Keep the versions in step with the sites the plugin runs on.
set -euo pipefail

wp_version=7.1.3
woocommerce_version=11.2.0

plugin="$(cd "$(dirname "$0")/../.." && pwd)"
if [ "$(basename "$(dirname "$plugin")")" != plugins ]; then
	echo "The plugin must be at <wordpress>/wp-content/plugins/<folder>, not ${plugin}." >&2
	exit 1
fi
wordpress="$(dirname "$(dirname "$(dirname "$plugin")")")"

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

echo "WordPress ${wp_version} into ${wordpress}"
curl -fsSL "https://wordpress.org/wordpress-${wp_version}.tar.gz" | tar -xz -C "$wordpress" --strip-components=1

echo "WooCommerce ${woocommerce_version}"
curl -fsSL -o "$tmp/woocommerce.zip" "https://downloads.wordpress.org/plugin/woocommerce.${woocommerce_version}.zip"
rm -rf "$wordpress/wp-content/plugins/woocommerce"
unzip -q "$tmp/woocommerce.zip" -d "$wordpress/wp-content/plugins"
