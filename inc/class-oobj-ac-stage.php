<?php
/**
 * Class OOBJ_AC_Stage.
 *
 * @package oobj-abandoned-carts
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * How far a cart got before the shopper left, and which order statuses mean it was completed.
 */
class OOBJ_AC_Stage {

	/**
	 * Order statuses (without `wc-`) that mean the shopper finished checking out: paid, or on hold (waiting
	 * for the shop, e.g. a payment under review). Those carts aren't abandoned. Filter: oobj_ac_completed_statuses.
	 *
	 * @return string[]
	 */
	public static function completed_statuses(): array {
		$statuses = array_merge( wc_get_is_paid_statuses(), array( 'on-hold', 'refunded' ) );
		return array_values( array_unique( (array) apply_filters( 'oobj_ac_completed_statuses', $statuses ) ) );
	}

	/**
	 * The furthest stage a cart reached.
	 *
	 * @param array $cart A row from OOBJ_AC_Store::abandoned() (with `order_status`).
	 * @return array{key: string, label: string}
	 */
	public static function of( array $cart ): array {
		$status = $cart['order_status'] ?? null;
		if ( ! $status || 'checkout-draft' === $status ) {
			return $cart['checkout_at'] || $cart['order_id']
				? array(
					'key'   => 'checkout',
					'label' => __( 'Reached checkout', 'oobj-abandoned-carts' ),
				)
				: array(
					'key'   => 'cart',
					'label' => __( 'Cart only', 'oobj-abandoned-carts' ),
				);
		}
		$labels = array(
			'pending'   => __( 'Order placed, not paid', 'oobj-abandoned-carts' ),
			'failed'    => __( 'Payment failed', 'oobj-abandoned-carts' ),
			'cancelled' => __( 'Order cancelled (unpaid)', 'oobj-abandoned-carts' ),
		);
		return array(
			'key'   => $status,
			'label' => $labels[ $status ] ?? wc_get_order_status_name( $status ),
		);
	}
}
