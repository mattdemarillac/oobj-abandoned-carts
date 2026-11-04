<?php
/**
 * PHPUnit bootstrap: WordPress test suite + WooCommerce, with this plugin loaded.
 *
 * @package oobj-abandoned-carts
 */

declare( strict_types=1 );

$oobj_vendor = dirname( __DIR__ ) . '/vendor';

// With PCOV (composer test:coverage), record what runs while WordPress and the plugin load: the plugin file,
// boot() and the modules' register(). PHPUnit measures only during tests, so OOBJ_AC_Bootstrap_Coverage adds
// this to the report.
$oobj_pcov = function_exists( 'pcov\start' ) && ini_get( 'pcov.enabled' );
if ( $oobj_pcov ) {
	\pcov\start();
}

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
require __DIR__ . '/class-oobj-ac-test-stopped.php';
require __DIR__ . '/class-oobj-ac-test-saved-carts.php';

if ( $oobj_pcov ) {
	\pcov\stop();
	$GLOBALS['oobj_ac_bootstrap_coverage'] = \pcov\collect( \pcov\all );
	\pcov\clear();
}
