<?php
/**
 * Class OOBJ_AC_GA4.
 *
 * @package oobj-abandoned-carts
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Google Analytics 4 for "Email me my cart": loads assets/adapters/ga4.js wherever the form's script loads, which
 * sends the event save_cart when a cart is sent, only if the page has GA's gtag(). The plugin doesn't load GA
 * itself. Turn it off with the filter oobj_ac_ga4 (return false).
 */
class OOBJ_AC_GA4 implements OOBJ_AC_Module {

	public function register(): void {
		// After OOBJ_AC_Saved_Carts::enqueue() (priority 10), to follow the form's script.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20 );
	}

	public function enqueue(): void {
		if ( ! wp_script_is( 'oobj-ac-save', 'enqueued' ) || ! apply_filters( 'oobj_ac_ga4', true ) ) {
			return;
		}
		wp_enqueue_script(
			'oobj-ac-ga4',
			plugins_url( 'assets/adapters/ga4.js', OOBJ_ABANDONED_CARTS_FILE ),
			array(),
			(string) filemtime( dirname( OOBJ_ABANDONED_CARTS_FILE ) . '/assets/adapters/ga4.js' ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}
}
