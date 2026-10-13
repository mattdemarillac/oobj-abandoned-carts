<?php
/**
 * Class OOBJ_AC_Backfill.
 *
 * @package oobj-abandoned-carts
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Records the carts in WooCommerce's sessions table that the store doesn't have yet. Run on activation, as
 * the table only has the sessions WooCommerce hasn't expired (2 days for guests, a week for accounts).
 *
 * A session doesn't say when it started or last changed, so its last activity is worked out from its expiry.
 */
class OOBJ_AC_Backfill {

	const BATCH = 500;

	/**
	 * The carts table.
	 *
	 * @var OOBJ_AC_Store
	 */
	private $store;

	public function __construct( OOBJ_AC_Store $store ) {
		$this->store = $store;
	}

	/**
	 * Record every session cart not already in the store.
	 *
	 * @return int How many carts were added.
	 */
	public function run(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'woocommerce_sessions';
		$added = 0;
		$after = 0;
		do {
			$fetched = 0;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT session_id, session_key, session_value, session_expiry FROM %i WHERE session_id > %d AND session_expiry > %d ORDER BY session_id LIMIT %d', $table, $after, time(), self::BATCH ) );
			foreach ( $rows as $row ) {
				++$fetched;
				$after = (int) $row->session_id;
				$cart  = $this->from_session( (string) $row->session_key, (string) $row->session_value, (int) $row->session_expiry );
				if ( $cart && ! $this->store->exists_for_key( $cart['cart_key'] ) ) {
					$this->store->insert( $cart );
					++$added;
				}
			}
		} while ( self::BATCH === $fetched );
		return $added;
	}

	/**
	 * A cart row from one session, or null if the session has no cart.
	 *
	 * @param string $key    The session's customer ID.
	 * @param string $value  The serialized session data.
	 * @param int    $expiry When the session expires (Unix time).
	 */
	public function from_session( string $key, string $value, int $expiry ): ?array {
		$data = maybe_unserialize( $value );
		if ( ! is_array( $data ) ) {
			return null;
		}
		$session = array_map( 'maybe_unserialize', $data );
		$lines   = is_array( $session['cart'] ?? null ) ? $session['cart'] : array();

		$items = array();
		$count = 0;
		foreach ( $lines as $line ) {
			$product_id   = (int) ( $line['product_id'] ?? 0 );
			$variation_id = (int) ( $line['variation_id'] ?? 0 );
			$product      = wc_get_product( $variation_id ? $variation_id : $product_id );
			$quantity     = (int) ( $line['quantity'] ?? 0 );
			$count       += $quantity;
			$items[]      = array(
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
				'name'         => $product ? wp_strip_all_tags( $product->get_name() ) : '',
				'quantity'     => $quantity,
				'line_total'   => round( (float) ( $line['line_total'] ?? 0 ) + (float) ( $line['line_tax'] ?? 0 ), 4 ),
			);
		}
		if ( ! $items ) {
			return null;
		}

		$user_id  = ctype_digit( $key ) && get_userdata( (int) $key ) ? (int) $key : 0;
		$lifetime = $user_id ? WEEK_IN_SECONDS : 2 * DAY_IN_SECONDS;
		$active   = gmdate( 'Y-m-d H:i:s', min( time(), $expiry - $lifetime ) );
		$customer = is_array( $session['customer'] ?? null ) ? $session['customer'] : array();
		$totals   = is_array( $session['cart_totals'] ?? null ) ? $session['cart_totals'] : array();
		$order_id = (int) ( $session['store_api_draft_order'] ?? 0 );
		$order_id = $order_id ? $order_id : (int) ( $session['order_awaiting_payment'] ?? 0 );
		$email    = (string) ( $customer['email'] ?? '' );

		return array(
			'cart_key'    => $key,
			'user_id'     => $user_id,
			'email'       => is_email( $email ) ? $email : '',
			'name'        => trim( ( $customer['first_name'] ?? '' ) . ' ' . ( $customer['last_name'] ?? '' ) ),
			'items'       => $items,
			'item_count'  => $count,
			'total'       => round( (float) ( $totals['total'] ?? 0 ), 4 ),
			'currency'    => get_woocommerce_currency(),
			'order_id'    => $order_id,
			'created_at'  => $active,
			'updated_at'  => $active,
			'checkout_at' => $order_id ? $active : null,
		);
	}
}
