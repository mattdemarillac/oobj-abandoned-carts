<?php
/**
 * Base test case: products, a fresh cart and session, orders, and direct access to the carts table.
 *
 * @package oobj-abandoned-carts
 */

declare( strict_types=1 );

abstract class OOBJ_AC_TestCase extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		WC()->cart->empty_cart();
		WC()->session->set( OOBJ_AC_Tracker::SESSION_KEY, null );
		$this->store()->prune( 0 );
	}

	public function tear_down() {
		WC()->cart->empty_cart();
		WC()->session->set( OOBJ_AC_Tracker::SESSION_KEY, null );
		remove_all_filters( 'oobj_ac_completed_statuses' );
		remove_all_filters( 'oobj_ac_retention_days' );
		parent::tear_down();
	}

	protected function store(): OOBJ_AC_Store {
		return oobj_abandoned_carts( OOBJ_AC_Store::class );
	}

	protected function create_product( string $name = 'Titanium Hoop', string $price = '10' ): WC_Product {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_status( 'publish' );
		$product->set_regular_price( $price );
		$product->save();
		return $product;
	}

	/**
	 * Add a product to the cart and let WooCommerce save the cart (which fires woocommerce_cart_updated).
	 */
	protected function add_to_cart( WC_Product $product, int $quantity = 1 ): string {
		$key = WC()->cart->add_to_cart( $product->get_id(), $quantity );
		WC()->cart->calculate_totals();
		return (string) $key;
	}

	/**
	 * Every cart row, oldest first.
	 *
	 * @return array[]
	 */
	protected function all_carts(): array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i ORDER BY id', $this->store()->table() ) );
		return array_map( fn ( $id ) => $this->store()->get( (int) $id ), $ids );
	}

	protected function only_cart(): array {
		$carts = $this->all_carts();
		$this->assertCount( 1, $carts );
		return $carts[0];
	}

	protected function create_order( string $status = 'pending', string $email = 'sam@example.org' ): WC_Order {
		$order = wc_create_order();
		$order->set_billing_email( $email );
		$order->set_billing_first_name( 'Sam' );
		$order->set_billing_last_name( 'Shopper' );
		$order->set_status( $status );
		$order->save();
		return $order;
	}

	/**
	 * A cart row with the given columns, last active $days_ago days ago.
	 */
	protected function insert_cart( array $data = array(), float $days_ago = 0 ): int {
		$at = gmdate( 'Y-m-d H:i:s', (int) ( time() - $days_ago * DAY_IN_SECONDS ) );
		return $this->store()->insert(
			$data + array(
				'cart_key'   => 't_' . wp_generate_password( 12, false ),
				'items'      => array(
					array(
						'product_id'   => 1,
						'variation_id' => 0,
						'name'         => 'Titanium Hoop',
						'quantity'     => 1,
						'line_total'   => 10.0,
					),
				),
				'item_count' => 1,
				'total'      => 10,
				'currency'   => 'AUD',
				'created_at' => $at,
				'updated_at' => $at,
			)
		);
	}

	protected function abandoned_ids( int $days = 7 ): array {
		return array_column( $this->store()->abandoned( $days )['rows'], 'id' );
	}
}
