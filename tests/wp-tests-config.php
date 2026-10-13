<?php
/**
 * WordPress test suite config. The suite drops and reinstalls every table in DB_NAME on each run,
 * so DB_NAME must be a database used only by the tests, never the site's.
 *
 * @package oobj-abandoned-carts
 */

declare( strict_types=1 );

/**
 * An environment variable, or $fallback when it isn't set or is empty.
 */
function oobj_tests_env( $name, $fallback ) {
	$value = getenv( $name );
	return false === $value || '' === $value ? $fallback : $value;
}

define( 'ABSPATH', oobj_tests_env( 'WP_TESTS_ABSPATH', dirname( __DIR__, 4 ) . '/' ) );

define( 'DB_NAME', oobj_tests_env( 'WP_TESTS_DB_NAME', 'oobj_abandoned_carts_tests' ) );
define( 'DB_USER', oobj_tests_env( 'WP_TESTS_DB_USER', 'wordpress' ) );
define( 'DB_PASSWORD', oobj_tests_env( 'WP_TESTS_DB_PASSWORD', '' ) );
define( 'DB_HOST', oobj_tests_env( 'WP_TESTS_DB_HOST', 'mysql' ) );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
define( 'WP_DEBUG', true );

// The site's object-cache.php drop-in (LiteSpeed) would otherwise share Redis with the dev site.
define( 'LSCWP_OBJECT_CACHE', false );
