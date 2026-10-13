<?php
/**
 * PHPUnit bootstrap: WordPress test suite + WooCommerce, with this plugin loaded.
 *
 * @package oobj-abandoned-carts
 */

declare( strict_types=1 );

$oobj_vendor = dirname( __DIR__ ) . '/vendor';

define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $oobj_vendor . '/yoast/phpunit-polyfills' );

$oobj_tests_dir = $oobj_vendor . '/wp-phpunit/wp-phpunit';

require_once $oobj_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	function () {
		require WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		require dirname( __DIR__ ) . '/oobj-abandoned-carts.php';
	}
);

tests_add_filter(
	'setup_theme',
	function () {
		WC_Install::install();
		$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_roles();
	}
);

require $oobj_tests_dir . '/includes/bootstrap.php';
require __DIR__ . '/class-oobj-ac-testcase.php';
