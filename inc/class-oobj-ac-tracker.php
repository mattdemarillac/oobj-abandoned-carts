<?php
/**
 * Class OOBJ_AC_Tracker.
 *
 * @package oobj-abandoned-carts
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Records the shopper's cart in the store whenever WooCommerce saves it, with who they are (once known) and
 * the order that checkout creates for it.
 *
 * The cart's row ID is kept in the WooCommerce session, which follows the shopper when they log in.
 */
class OOBJ_AC_Tracker implements OOBJ_AC_Module {

	const SESSION_KEY = 'oobj_ac';

	/**
	 * When nothing in the cart changed, how often (seconds) to still update its last activity.
	 */
	const TOUCH_INTERVAL = 600;

	/**
	 * The carts table.
	 *
	 * @var OOBJ_AC_Store
	 */
	private $store;

	public function __construct( OOBJ_AC_Store $store ) {
		$this->store = $store;
	}

	public function register(): void {
		add_action( 'woocommerce_cart_updated', array( $this, 'save_cart' ) );
		add_action( 'woocommerce_cart_emptied', array( $this, 'cart_emptied' ) );
		add_action( 'woocommerce_store_api_cart_update_customer_from_request', array( $this, 'save_customer' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'link_order' ) );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'link_classic_order' ), 10, 3 );
	}

	/**
	 * Save the current cart (WooCommerce has just saved it to the session).
	 */
	public function save_cart(): void {
		if ( ! $this->has_session() ) {
			return;
		}
		$cart = WC()->cart;
		if ( $cart->is_empty() ) {
			$this->forget_cart();
			return;
		}

		$snapshot = $this->snapshot( $cart );
		$hash     = md5( (string) wp_json_encode( array( $snapshot['items'], $snapshot['total'] ) ) );
		$state    = $this->state();
		if ( $state['id'] && $state['hash'] === $hash && time() - $state['at'] < self::TOUCH_INTERVAL ) {
			return;
		}

		$data = $snapshot + $this->customer_details( WC()->customer ) + array( 'updated_at' => current_time( 'mysql', true ) );
		$id   = $this->current_id();
		if ( $id ) {
			$this->store->update( $id, $data );
		} else {
			$id = $this->store->insert( $data + array( 'cart_key' => (string) WC()->session->get_customer_id() ) );
		}
		$this->set_state( $id, $hash );
	}

	/**
	 * The cart was emptied: forget it, unless it has an order (checkout empties the cart once the order is
	 * placed, before it's paid). Logging out empties the cart but keeps the account's saved cart, so that
	 * cart stays.
	 *
	 * @param bool $clear_persistent_cart Whether the account's saved cart was cleared too.
	 */
	public function cart_emptied( $clear_persistent_cart = true ): void {
		if ( $clear_persistent_cart && $this->has_session() ) {
			$this->forget_cart();
		}
	}

	/**
	 * The block checkout sent the shopper's details (e.g. as they type their email).
	 *
	 * @param WC_Customer $customer The customer.
	 */
	public function save_customer( $customer ): void {
		$id = $this->has_session() ? $this->current_id() : 0;
		if ( $id && $customer instanceof WC_Customer ) {
			$this->store->update( $id, $this->customer_details( $customer ) );
		}
	}

	/**
	 * The block checkout created or updated the cart's order (a draft as soon as checkout loads).
	 *
	 * @param WC_Order $order The order.
	 */
	public function link_order( $order ): void {
		if ( ! $order instanceof WC_Order || ! $this->has_session() ) {
			return;
		}
		$id = $this->current_id();
		if ( ! $id ) {
			return;
		}
		$cart = $this->store->get( $id );
		$data = array( 'order_id' => $order->get_id() ) + $this->order_details( $order );
		if ( $cart && ! $cart['checkout_at'] ) {
			$data['checkout_at'] = current_time( 'mysql', true );
		}
		$this->store->update( $id, $data );
	}

	/**
	 * The classic checkout placed the cart's order.
	 *
	 * @param int      $order_id    The order's ID.
	 * @param array    $posted_data The checkout form.
	 * @param WC_Order $order       The order.
	 */
	public function link_classic_order( $order_id, $posted_data = array(), $order = null ): void {
		$this->link_order( $order instanceof WC_Order ? $order : wc_get_order( $order_id ) );
	}

	/**
	 * The cart's items and total.
	 *
	 * @param WC_Cart $cart The cart.
	 * @return array{items: array, item_count: int, total: float, currency: string}
	 */
	private function snapshot( WC_Cart $cart ): array {
		$items = array();
		foreach ( $cart->get_cart() as $item ) {
			$product = $item['data'] ?? null;
			$items[] = array(
				'product_id'   => (int) ( $item['product_id'] ?? 0 ),
				'variation_id' => (int) ( $item['variation_id'] ?? 0 ),
				'name'         => $product instanceof WC_Product ? wp_strip_all_tags( $product->get_name() ) : '',
				'quantity'     => (int) ( $item['quantity'] ?? 0 ),
				'line_total'   => round( (float) ( $item['line_total'] ?? 0 ) + (float) ( $item['line_tax'] ?? 0 ), 4 ),
			);
		}
		return array(
			'items'      => $items,
			'item_count' => (int) $cart->get_cart_contents_count(),
			'total'      => round( (float) $cart->get_total( 'edit' ), 4 ),
			'currency'   => get_woocommerce_currency(),
		);
	}

	/**
	 * Who the shopper is, as far as it's known. Unknown details are left out so they don't blank known ones.
	 *
	 * @param WC_Customer|null $customer The customer.
	 */
	private function customer_details( $customer ): array {
		$data = array();
		if ( get_current_user_id() ) {
			$data['user_id'] = get_current_user_id();
		}
		if ( $customer instanceof WC_Customer ) {
			$email = $customer->get_billing_email() ? $customer->get_billing_email() : $customer->get_email();
			$name  = trim( $customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name() );
			if ( is_email( $email ) ) {
				$data['email'] = $email;
			}
			if ( '' !== $name ) {
				$data['name'] = $name;
			}
		}
		return $data;
	}

	private function order_details( WC_Order $order ): array {
		$data = array();
		if ( is_email( $order->get_billing_email() ) ) {
			$data['email'] = $order->get_billing_email();
		}
		$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		if ( '' !== $name ) {
			$data['name'] = $name;
		}
		if ( $order->get_customer_id() ) {
			$data['user_id'] = $order->get_customer_id();
		}
		return $data;
	}

	/**
	 * The open cart for this session: the one in the session if it's still open, else the newest open cart
	 * with the session's key (e.g. one recorded from WooCommerce's sessions on activation), else 0.
	 */
	private function current_id(): int {
		$state = $this->state();
		if ( $state['id'] && $this->store->is_open( $state['id'] ) ) {
			return $state['id'];
		}
		$cart = $this->store->find_open( (string) WC()->session->get_customer_id() );
		if ( $cart ) {
			$this->set_state( $cart['id'], '' );
			return $cart['id'];
		}
		if ( $state['id'] ) {
			WC()->session->set( self::SESSION_KEY, null );
		}
		return 0;
	}

	private function forget_cart(): void {
		$id = $this->current_id();
		if ( $id ) {
			$cart = $this->store->get( $id );
			if ( $cart && ! $cart['order_id'] ) {
				$this->store->delete( $id );
			}
		}
		WC()->session->set( self::SESSION_KEY, null );
	}

	/**
	 * The cart's state in the session.
	 *
	 * @return array{id: int, hash: string, at: int}
	 */
	private function state(): array {
		$state = WC()->session->get( self::SESSION_KEY );
		$state = is_array( $state ) ? $state : array();
		return array(
			'id'   => (int) ( $state['id'] ?? 0 ),
			'hash' => (string) ( $state['hash'] ?? '' ),
			'at'   => (int) ( $state['at'] ?? 0 ),
		);
	}

	private function set_state( int $id, string $hash ): void {
		WC()->session->set(
			self::SESSION_KEY,
			array(
				'id'   => $id,
				'hash' => $hash,
				'at'   => time(),
			)
		);
	}

	private function has_session(): bool {
		return function_exists( 'WC' ) && WC()->session && WC()->cart && WC()->session->get_customer_id();
	}
}
