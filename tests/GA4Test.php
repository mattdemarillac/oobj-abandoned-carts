<?php
/**
 * OOBJ_AC_GA4: the GA4 adapter's script, where the form's script loads.
 *
 * @package oobj-abandoned-carts
 */

declare( strict_types=1 );

class GA4Test extends OOBJ_AC_TestCase {

	public function tear_down() {
		wp_dequeue_script( 'oobj-ac-save' );
		wp_dequeue_script( 'oobj-ac-ga4' );
		remove_all_filters( 'oobj_ac_ga4' );
		parent::tear_down();
	}

	public function test_loads_after_the_forms_script() {
		$ga4 = oobj_abandoned_carts( OOBJ_AC_GA4::class );
		$this->assertSame( 20, has_action( 'wp_enqueue_scripts', array( $ga4, 'enqueue' ) ) );

		$ga4->enqueue();
		$this->assertFalse( wp_script_is( 'oobj-ac-ga4', 'enqueued' ), 'No form on the page' );

		wp_enqueue_script( 'oobj-ac-save', 'https://example.org/save-cart.js', array(), '1', true );
		$ga4->enqueue();
		$this->assertTrue( wp_script_is( 'oobj-ac-ga4', 'enqueued' ) );
		$this->assertStringContainsString( 'assets/adapters/ga4.js', wp_scripts()->registered['oobj-ac-ga4']->src );
	}

	public function test_turned_off_by_the_filter() {
		wp_enqueue_script( 'oobj-ac-save', 'https://example.org/save-cart.js', array(), '1', true );
		add_filter( 'oobj_ac_ga4', '__return_false' );

		oobj_abandoned_carts( OOBJ_AC_GA4::class )->enqueue();

		$this->assertFalse( wp_script_is( 'oobj-ac-ga4', 'enqueued' ) );
	}

	public function test_the_script_sends_only_when_the_page_has_gtag() {
		$script = (string) file_get_contents( dirname( OOBJ_ABANDONED_CARTS_FILE ) . '/assets/adapters/ga4.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A plugin file.

		$this->assertStringContainsString( "document.addEventListener( 'oobj-ac:saved'", $script );
		$this->assertMatchesRegularExpression( "/if \( 'function' === typeof window\.gtag \) \{\s*window\.gtag\( 'event', 'save_cart' \);/", $script );
		$this->assertStringNotContainsString( 'gtag', (string) file_get_contents( dirname( OOBJ_ABANDONED_CARTS_FILE ) . '/assets/save-cart.js' ), 'The form knows no analytics' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A plugin file.
	}
}
