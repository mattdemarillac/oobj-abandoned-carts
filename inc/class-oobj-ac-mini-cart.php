<?php
/**
 * Class OOBJ_AC_Mini_Cart.
 *
 * @package oobj-abandoned-carts
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * "Email me this cart" in WooCommerce's Mini-Cart block: wherever the block renders, assets/mini-cart.js adds a link
 * under the drawer's buttons to the form on the cart page. The link's address and text are in the page, on a hidden
 * element after the block (not an inline script, which optimisers such as LiteSpeed's reorder or delay). The link is
 * the same on every page, so cached pages can carry it.
 */
class OOBJ_AC_Mini_Cart implements OOBJ_AC_Module {

	public function register(): void {
		add_filter( 'render_block_woocommerce/mini-cart', array( $this, 'enqueue' ) );
	}

	/**
	 * Loads the link's script and style with the Mini-Cart block, and adds the link's address and text after it.
	 *
	 * @internal Filter callback.
	 * @param string $html The block.
	 */
	public function enqueue( $html ): string {
		$dir = dirname( OOBJ_ABANDONED_CARTS_FILE );
		wp_enqueue_style( 'oobj-ac-mini-cart', plugins_url( 'assets/mini-cart.css', OOBJ_ABANDONED_CARTS_FILE ), array(), (string) filemtime( "$dir/assets/mini-cart.css" ) );
		wp_enqueue_script(
			'oobj-ac-mini-cart',
			plugins_url( 'assets/mini-cart.js', OOBJ_ABANDONED_CARTS_FILE ),
			array(),
			(string) filemtime( "$dir/assets/mini-cart.js" ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		return (string) $html . sprintf(
			'<span class="oobj-ac-mini-cart-link" hidden data-url="%s" data-text="%s"></span>',
			esc_url( wc_get_cart_url() . '#save-cart' ),
			esc_attr__( 'Email me this cart', 'oobj-abandoned-carts' )
		);
	}
}
