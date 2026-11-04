<?php
/**
 * Class OOBJ_AC_Plugin.
 *
 * @package oobj-abandoned-carts
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * The composition root: creates every service once, wires them together and registers the modules.
 */
class OOBJ_AC_Plugin {

	/**
	 * Services by class name.
	 *
	 * @var array<string, object>
	 */
	private $services = array();

	public function boot(): void {
		$store = $this->add( new OOBJ_AC_Store() );
		$store->maybe_install();
		$this->add( new OOBJ_AC_Tracker( $store ) );
		$this->add( new OOBJ_AC_Cleanup( $store ) );
		$this->add( new OOBJ_AC_Admin_Page( $store ) );
		$this->add( new OOBJ_AC_Saved_Carts( $store ) );
	}

	/**
	 * Activation: create the table, then record the carts WooCommerce still has in its sessions (the last two
	 * days), so the list isn't empty until shoppers come back.
	 */
	public static function activate(): void {
		// WordPress doesn't activate it without WooCommerce (Requires Plugins); the tests load WooCommerce.
		// phpcs:disable Squiz.Commenting.InlineComment.InvalidEndChar -- PHPUnit needs the marker exactly.
		// @codeCoverageIgnoreStart
		if ( ! function_exists( 'WC' ) ) {
			return;
		}
		// @codeCoverageIgnoreEnd
		// phpcs:enable Squiz.Commenting.InlineComment.InvalidEndChar
		$store = new OOBJ_AC_Store();
		$store->install();
		( new OOBJ_AC_Backfill( $store ) )->run();
	}

	public static function deactivate(): void {
		OOBJ_AC_Cleanup::unschedule();
	}

	/**
	 * Keep a service, registering its hooks if it's a module.
	 *
	 * @param object $service The service.
	 * @return object The same service.
	 */
	private function add( object $service ): object {
		if ( $service instanceof OOBJ_AC_Module ) {
			$service->register();
		}
		$this->services[ get_class( $service ) ] = $service;
		return $service;
	}

	/**
	 * A service by class name.
	 *
	 * @param string $class_name The class.
	 */
	public function get( string $class_name ): ?object {
		return $this->services[ $class_name ] ?? null;
	}
}
