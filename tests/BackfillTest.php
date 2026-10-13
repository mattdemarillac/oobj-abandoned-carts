<?php
/**
 * Recording the carts already in WooCommerce's sessions.
 *
 * @package oobj-abandoned-carts
 */

declare( strict_types=1 );

class BackfillTest extends OOBJ_AC_TestCase {

	public function set_up() {
		parent::set_up();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_sessions" );
	}

	/**
	 * A session row stored as WooCommerce stores it: each value serialized, then the whole array.
	 */
	private function add_session( string $key, array $data, int $expiry ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'woocommerce_sessions',
			array(
				'session_key'    => $key,
				'session_value'  => maybe_serialize( array_map( 'maybe_serialize', $data ) ),
				'session_expiry' => $expiry,
			)
		);
	}

	private function session_cart( WC_Product $product, int $quantity = 2 ): array {
		return array(
			'cart'        => array(
				'abc' => array(
					'key'          => 'abc',
					'product_id'   => $product->get_id(),
					'variation_id' => 0,
					'quantity'     => $quantity,
					'line_total'   => 20.0,
					'line_tax'     => 2.0,
				),
			),
			'cart_totals' => array( 'total' => '22.00' ),
			'customer'    => array(
				'email'      => 'sam@example.org',
				'first_name' => 'Sam',
				'last_name'  => 'Shopper',
			),
		);
	}

	public function test_records_session_carts_once() {
		$product = $this->create_product( 'Titanium Hoop' );
		$this->add_session( 't_guest', $this->session_cart( $product ), time() + DAY_IN_SECONDS );
		$this->add_session( 't_nocart', array( 'customer' => array( 'email' => 'x@example.org' ) ), time() + DAY_IN_SECONDS );
		$this->add_session( 't_expired', $this->session_cart( $product ), time() - 60 );

		$backfill = new OOBJ_AC_Backfill( $this->store() );
		$this->assertSame( 1, $backfill->run() );
		$this->assertSame( 0, $backfill->run() );

		$cart = $this->only_cart();
		$this->assertSame( 't_guest', $cart['cart_key'] );
		$this->assertSame( 'sam@example.org', $cart['email'] );
		$this->assertSame( 'Sam Shopper', $cart['name'] );
		$this->assertSame( 2, $cart['item_count'] );
		$this->assertEqualsWithDelta( 22.0, $cart['total'], 0.001 );
		$this->assertSame( 'Titanium Hoop', $cart['items'][0]['name'] );
		$this->assertEqualsWithDelta( 22.0, $cart['items'][0]['line_total'], 0.001 );
		// A guest session lasts two days, so expiring in one day means it was last active a day ago.
		$this->assertEqualsWithDelta( time() - DAY_IN_SECONDS, strtotime( $cart['updated_at'] . ' UTC' ), 5 );
	}

	public function test_account_sessions_last_a_week_and_keep_the_user_and_order() {
		$user  = self::factory()->user->create();
		$order = $this->create_order( 'checkout-draft' );
		$this->add_session( (string) $user, $this->session_cart( $this->create_product() ) + array( 'store_api_draft_order' => $order->get_id() ), time() + 5 * DAY_IN_SECONDS );

		( new OOBJ_AC_Backfill( $this->store() ) )->run();

		$cart = $this->only_cart();
		$this->assertSame( $user, $cart['user_id'] );
		$this->assertSame( $order->get_id(), $cart['order_id'] );
		$this->assertNotEmpty( $cart['checkout_at'] );
		$this->assertEqualsWithDelta( time() - 2 * DAY_IN_SECONDS, strtotime( $cart['updated_at'] . ' UTC' ), 5 );
	}
}
