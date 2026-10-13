<?php
/**
 * Interface OOBJ_AC_Module.
 *
 * @package oobj-abandoned-carts
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * A part of the plugin that hooks into WordPress.
 */
interface OOBJ_AC_Module {

	/**
	 * Add the module's hooks.
	 */
	public function register(): void;
}
