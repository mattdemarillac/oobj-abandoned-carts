<?php
/**
 * Plugin Name:       Saved Carts
 * Description:       Keeps every cart shoppers fill, so you can see the ones that didn't become an order (WooCommerce → Saved carts), and lets shoppers email themselves their cart to come back to on any device.
 * Version:           0.1.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Update URI:        https://github.com/mattdemarillac/oobj-abandoned-carts
 * Author:            Out Of Body Jewellery
 * License:           Use and modification permitted, no redistribution (see LICENSE)
 * License URI:       LICENSE
 * Text Domain:       oobj-abandoned-carts
 *
 * @package oobj-abandoned-carts
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'OOBJ_ABANDONED_CARTS_FILE', __FILE__ );
define( 'OOBJ_ABANDONED_CARTS_VERSION', '0.1.0' );

/*
 * Classes: OOBJ_AC_Foo_Bar lives in inc/class-oobj-ac-foo-bar.php (interfaces in interface-oobj-ac-*.php).
 */
spl_autoload_register(
	function ( $class_name ) {
		if ( ! str_starts_with( $class_name, 'OOBJ_AC_' ) ) {
			return;
		}
		$slug = strtolower( str_replace( '_', '-', $class_name ) );
		foreach ( array( 'class', 'interface' ) as $kind ) {
			$file = __DIR__ . "/inc/$kind-$slug.php";
			if ( is_readable( $file ) ) {
				require $file;
				return;
			}
		}
	}
);

/**
 * The plugin, or one of its services by class name.
 *
 * @template T of object
 * @param class-string<T>|null $class_name A service's class, or null for the plugin.
 * @return ($class_name is null ? OOBJ_AC_Plugin : T|null)
 */
function oobj_abandoned_carts( ?string $class_name = null ) {
	static $plugin = null;
	if ( null === $plugin ) {
		$plugin = new OOBJ_AC_Plugin();
		$plugin->boot();
	}
	return null === $class_name ? $plugin : $plugin->get( $class_name );
}

add_action(
	'plugins_loaded',
	function () {
		if ( function_exists( 'WC' ) ) {
			oobj_abandoned_carts();
		}
	}
);

register_activation_hook( __FILE__, array( 'OOBJ_AC_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'OOBJ_AC_Plugin', 'deactivate' ) );
