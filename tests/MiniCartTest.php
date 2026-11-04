<?php
/**
 * OOBJ_AC_Mini_Cart: the "Email me this cart" link in WooCommerce's Mini-Cart drawer.
 *
 * @package oobj-abandoned-carts
 */

declare( strict_types=1 );

class MiniCartTest extends OOBJ_AC_TestCase {

	public function test_loads_with_the_mini_cart_block() {
		$mini_cart = oobj_abandoned_carts( OOBJ_AC_Mini_Cart::class );
		$this->assertSame( 10, has_filter( 'render_block_woocommerce/mini-cart', array( $mini_cart, 'enqueue' ) ) );
		wp_dequeue_script( 'oobj-ac-mini-cart' );

		$html = $mini_cart->enqueue( '<div>mini cart</div>' );

		$this->assertSame( '<div>mini cart</div><span class="oobj-ac-mini-cart-link" hidden data-url="' . esc_url( wc_get_cart_url() . '#save-cart' ) . '" data-text="Email me this cart"></span>', $html, 'The block, then the link\'s address and text' );
		$this->assertTrue( wp_script_is( 'oobj-ac-mini-cart', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'oobj-ac-mini-cart', 'enqueued' ) );
		$this->assertSame( 'defer', wp_scripts()->get_data( 'oobj-ac-mini-cart', 'strategy' ) );
	}
}
