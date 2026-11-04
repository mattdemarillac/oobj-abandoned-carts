<?php
/**
 * Recording carts as WooCommerce saves them.
 *
 * @package oobj-abandoned-carts
 */

declare( strict_types=1 );

class TrackerTest extends OOBJ_AC_TestCase {

	public function test_adding_to_the_cart_records_it() {
		$product = $this->create_product( 'Titanium Hoop', '25' );
		$this->add_to_cart( $product, 2 );

		$cart = $this->only_cart();
		$this->assertSame( (string) WC()->session->get_customer_id(), $cart['cart_key'] );
		$this->assertSame( 2, $cart['item_count'] );
		$this->assertEqualsWithDelta( (float) WC()->cart->get_total( 'edit' ), $cart['total'], 0.001 );
		$this->assertSame( $product->get_id(), $cart['items'][0]['product_id'] );
		$this->assertSame( 'Titanium Hoop', $cart['items'][0]['name'] );
		$this->assertSame( 2, $cart['items'][0]['quantity'] );
		$this->assertSame( 0, $cart['order_id'] );
	}

	public function test_changing_the_cart_updates_the_same_cart() {
		$this->add_to_cart( $this->create_product( 'Hoop' ) );
		$this->add_to_cart( $this->create_product( 'Stud' ) );

		$cart = $this->only_cart();
		$this->assertSame( array( 'Hoop', 'Stud' ), array_column( $cart['items'], 'name' ) );
		$this->assertSame( 2, $cart['item_count'] );
	}

	public function test_removing_every_item_forgets_the_cart() {
		$key = $this->add_to_cart( $this->create_product() );
		WC()->cart->remove_cart_item( $key );
		WC()->cart->calculate_totals();

		$this->assertSame( array(), $this->all_carts() );
	}

	public function test_emptying_the_cart_forgets_it() {
		$this->add_to_cart( $this->create_product() );
		WC()->cart->empty_cart();

		$this->assertSame( array(), $this->all_carts() );
	}

	public function test_logging_out_keeps_the_cart() {
		$this->add_to_cart( $this->create_product() );
		do_action( 'woocommerce_cart_emptied', false );

		$this->assertCount( 1, $this->all_carts() );
	}

	public function test_emptying_the_cart_after_checkout_keeps_the_cart_with_its_order() {
		$this->add_to_cart( $this->create_product() );
		$order = $this->create_order( 'pending' );
		do_action( 'woocommerce_checkout_order_processed', $order->get_id(), array(), $order );
		WC()->cart->empty_cart();

		$this->assertSame( $order->get_id(), $this->only_cart()['order_id'] );
	}

	public function test_customer_details_from_the_block_checkout_are_saved() {
		$this->add_to_cart( $this->create_product() );
		$customer = new WC_Customer();
		$customer->set_billing_email( 'sam@example.org' );
		$customer->set_billing_first_name( 'Sam' );
		$customer->set_billing_last_name( 'Shopper' );
		do_action( 'woocommerce_store_api_cart_update_customer_from_request', $customer, new WP_REST_Request() );

		$cart = $this->only_cart();
		$this->assertSame( 'sam@example.org', $cart['email'] );
		$this->assertSame( 'Sam Shopper', $cart['name'] );
	}

	public function test_unknown_details_do_not_blank_known_ones() {
		$this->add_to_cart( $this->create_product() );
		$id = $this->only_cart()['id'];
		$this->store()->update(
			$id,
			array(
				'email' => 'sam@example.org',
				'name'  => 'Sam Shopper',
			)
		);
		$this->add_to_cart( $this->create_product( 'Stud' ) );

		$cart = $this->only_cart();
		$this->assertSame( 'sam@example.org', $cart['email'] );
		$this->assertSame( 'Sam Shopper', $cart['name'] );
	}

	public function test_the_block_checkout_links_its_draft_order() {
		$this->add_to_cart( $this->create_product() );
		$order = $this->create_order( 'checkout-draft' );
		do_action( 'woocommerce_store_api_checkout_update_order_meta', $order );

		$cart = $this->only_cart();
		$this->assertSame( $order->get_id(), $cart['order_id'] );
		$this->assertNotEmpty( $cart['checkout_at'] );
		$this->assertSame( 'sam@example.org', $cart['email'] );
		$this->assertSame( 'checkout', OOBJ_AC_Stage::of( $this->store()->abandoned( 7 )['rows'][0] )['key'] );
	}

	public function test_a_paid_order_completes_the_cart_and_the_next_cart_is_new() {
		$this->add_to_cart( $this->create_product() );
		$order = $this->create_order( 'pending' );
		do_action( 'woocommerce_store_api_checkout_update_order_meta', $order );
		$order->set_status( 'processing' );
		$order->save();
		WC()->cart->empty_cart();

		$this->assertSame( array(), $this->abandoned_ids() );

		$this->add_to_cart( $this->create_product( 'Stud' ) );
		$carts = $this->all_carts();
		$this->assertCount( 2, $carts );
		$this->assertSame( 0, $carts[1]['order_id'] );
		$this->assertSame( array( $carts[1]['id'] ), $this->abandoned_ids() );
	}

	public function test_a_cart_recorded_from_the_sessions_is_picked_up_by_its_key() {
		$id = $this->insert_cart( array( 'cart_key' => (string) WC()->session->get_customer_id() ) );
		$this->add_to_cart( $this->create_product( 'Stud' ) );

		$cart = $this->only_cart();
		$this->assertSame( $id, $cart['id'] );
		$this->assertSame( array( 'Stud' ), array_column( $cart['items'], 'name' ) );
	}

	public function test_an_unchanged_cart_is_not_written_again_straight_away() {
		$this->add_to_cart( $this->create_product() );
		$id = $this->only_cart()['id'];
		$this->store()->update( $id, array( 'updated_at' => '2020-01-01 00:00:00' ) );
		WC()->cart->calculate_totals();

		$this->assertSame( '2020-01-01 00:00:00', $this->store()->get( $id )['updated_at'] );
	}

	public function test_nothing_without_a_session() {
		$this->add_to_cart( $this->create_product() );
		$session      = WC()->session;
		WC()->session = null;
		$tracker      = oobj_abandoned_carts( OOBJ_AC_Tracker::class );
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $this->store()->table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$tracker->save_cart();

		WC()->session = $session;
		$this->assertSame( array(), $this->all_carts() );
	}

	public function test_an_order_is_linked_only_to_a_recorded_cart() {
		$tracker = oobj_abandoned_carts( OOBJ_AC_Tracker::class );
		$tracker->link_order( 'not an order' );
		$tracker->link_order( $this->create_order() );

		$this->assertSame( array(), $this->all_carts(), 'No cart to link it to' );
	}

	public function test_a_signed_in_shoppers_cart_has_their_account() {
		$user = self::factory()->user->create();
		wp_set_current_user( $user );

		$this->add_to_cart( $this->create_product() );

		$this->assertSame( $user, (int) $this->only_cart()['user_id'] );
	}

	public function test_the_orders_customer_account_is_recorded() {
		$this->add_to_cart( $this->create_product() );
		$user  = self::factory()->user->create();
		$order = $this->create_order();
		$order->set_customer_id( $user );
		$order->save();

		oobj_abandoned_carts( OOBJ_AC_Tracker::class )->link_order( $order );

		$this->assertSame( $user, (int) $this->only_cart()['user_id'] );
	}
}
