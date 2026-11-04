<?php
/**
 * Class OOBJ_AC_List_Table.
 *
 * @package oobj-abandoned-carts
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

// Loaded already wherever WordPress's admin is (the tests included).
// phpcs:disable Squiz.Commenting.InlineComment.InvalidEndChar -- PHPUnit needs the marker exactly.
// @codeCoverageIgnoreStart
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}
// @codeCoverageIgnoreEnd
// phpcs:enable Squiz.Commenting.InlineComment.InvalidEndChar

/**
 * The unfinished carts on the admin page, a page of 50 at a time.
 */
class OOBJ_AC_List_Table extends WP_List_Table {

	const PER_PAGE = 50;

	/**
	 * The carts table.
	 *
	 * @var OOBJ_AC_Store
	 */
	private $store;

	/**
	 * The period in days.
	 *
	 * @var int
	 */
	private $days;

	/**
	 * How many carts in the period, and their combined total.
	 *
	 * @var array{carts: int, value: float}
	 */
	private $totals = array(
		'carts' => 0,
		'value' => 0.0,
	);

	/**
	 * The listed carts' emailed copies (OOBJ_AC_Store::saved_for()), by cart.
	 *
	 * @var array<int, array{saved_at: string, restored_at: ?string}>
	 */
	private $saved = array();

	public function __construct( OOBJ_AC_Store $store, int $days ) {
		parent::__construct(
			array(
				'singular' => 'cart',
				'plural'   => 'carts',
				'ajax'     => false,
				'screen'   => 'woocommerce_page_' . OOBJ_AC_Admin_Page::SLUG,
			)
		);
		$this->store = $store;
		$this->days  = $days;
	}

	public function get_columns() {
		return array(
			'updated_at' => __( 'Last activity', 'oobj-abandoned-carts' ),
			'customer'   => __( 'Customer', 'oobj-abandoned-carts' ),
			'items'      => __( 'Items', 'oobj-abandoned-carts' ),
			'total'      => __( 'Total', 'oobj-abandoned-carts' ),
			'stage'      => __( 'Got as far as', 'oobj-abandoned-carts' ),
			'emailed'    => __( 'Emailed', 'oobj-abandoned-carts' ),
			'created_at' => __( 'Started', 'oobj-abandoned-carts' ),
		);
	}

	public function prepare_items() {
		$result                = $this->store->abandoned( $this->days, self::PER_PAGE, $this->get_pagenum() );
		$this->items           = $result['rows'];
		$this->saved           = $this->store->saved_for( array_column( $result['rows'], 'id' ) );
		$this->totals          = array(
			'carts' => $result['total'],
			'value' => $result['value'],
		);
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'updated_at' );
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	public function total_carts(): int {
		return $this->totals['carts'];
	}

	public function total_value(): float {
		return $this->totals['value'];
	}

	public function no_items() {
		esc_html_e( 'No unfinished carts in this period.', 'oobj-abandoned-carts' );
	}

	/**
	 * A date column: how long ago, with the date and time on hover.
	 *
	 * @param array  $item        The cart.
	 * @param string $column_name The column.
	 */
	public function column_default( $item, $column_name ) {
		if ( ! in_array( $column_name, array( 'updated_at', 'created_at' ), true ) ) {
			return '';
		}
		$time = strtotime( $item[ $column_name ] . ' UTC' );
		return sprintf(
			'<time datetime="%1$s" title="%2$s">%3$s</time>',
			esc_attr( gmdate( 'c', $time ) ),
			esc_attr( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) ),
			/* translators: %s: a time span, e.g. "3 hours". */
			esc_html( sprintf( __( '%s ago', 'oobj-abandoned-carts' ), human_time_diff( $time ) ) )
		);
	}

	/**
	 * Name, email and account, as far as they're known.
	 *
	 * @param array $item The cart.
	 */
	public function column_customer( $item ) {
		$lines = array();
		$user  = $item['user_id'] ? get_userdata( $item['user_id'] ) : false;
		$name  = $item['name'] ? $item['name'] : ( $user ? $user->display_name : '' );
		if ( $name ) {
			$lines[] = $user
				? sprintf( '<a href="%s"><strong>%s</strong></a>', esc_url( get_edit_user_link( $user->ID ) ), esc_html( $name ) )
				: sprintf( '<strong>%s</strong>', esc_html( $name ) );
		}
		$email = $item['email'] ? $item['email'] : ( $user ? $user->user_email : '' );
		if ( $email ) {
			$lines[] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( 'mailto:' . $email ), esc_html( $email ) );
		}
		if ( ! $lines ) {
			$lines[] = '<span class="oobj-ac-muted">' . esc_html__( 'Guest (no details given)', 'oobj-abandoned-carts' ) . '</span>';
		} elseif ( ! $user ) {
			$lines[] = '<span class="oobj-ac-muted">' . esc_html__( 'Guest', 'oobj-abandoned-carts' ) . '</span>';
		}
		return implode( '<br />', $lines );
	}

	/**
	 * Quantity × product, each linked to the product.
	 *
	 * @param array $item The cart.
	 */
	public function column_items( $item ) {
		$out = '<ul class="oobj-ac-items">';
		foreach ( $item['items'] as $line ) {
			$name = $line['name'] ? $line['name'] : sprintf( '#%d', $line['variation_id'] ? $line['variation_id'] : $line['product_id'] );
			$link = get_edit_post_link( $line['product_id'] );
			$out .= sprintf(
				'<li>%1$s × %2$s</li>',
				esc_html( number_format_i18n( $line['quantity'] ) ),
				$link ? sprintf( '<a href="%s">%s</a>', esc_url( $link ), esc_html( $name ) ) : esc_html( $name )
			);
		}
		return $out . '</ul>';
	}

	/**
	 * The cart total.
	 *
	 * @param array $item The cart.
	 */
	public function column_total( $item ) {
		return wp_kses_post( wc_price( $item['total'], array( 'currency' => $item['currency'] ) ) );
	}

	/**
	 * How far it got, with a link to the order once one was placed.
	 *
	 * @param array $item The cart.
	 */
	public function column_stage( $item ) {
		$stage = OOBJ_AC_Stage::of( $item );
		$out   = sprintf( '<mark class="oobj-ac-stage oobj-ac-stage-%1$s">%2$s</mark>', esc_attr( $stage['key'] ), esc_html( $stage['label'] ) );
		if ( ! in_array( $stage['key'], array( 'cart', 'checkout' ), true ) ) {
			$order = wc_get_order( $item['order_id'] );
			if ( $order ) {
				/* translators: %s: order number. */
				$out .= sprintf( '<br /><a href="%1$s">%2$s</a>', esc_url( $order->get_edit_order_url() ), esc_html( sprintf( __( 'Order #%s', 'oobj-abandoned-carts' ), $order->get_order_number() ) ) );
			}
		}
		return $out;
	}

	/**
	 * Whether the shopper emailed themselves the cart ("Email me my cart"), when, and whether they came back
	 * from the email.
	 *
	 * @param array $item The cart.
	 */
	public function column_emailed( $item ) {
		$saved = $this->saved[ (int) $item['id'] ] ?? null;
		if ( ! $saved ) {
			return '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'No', 'oobj-abandoned-carts' ) . '</span>';
		}
		/* translators: %s: how long ago, e.g. 2 days. */
		$out = esc_html( sprintf( __( 'Yes, %s ago', 'oobj-abandoned-carts' ), human_time_diff( (int) strtotime( $saved['saved_at'] . ' UTC' ) ) ) );
		if ( $saved['restored_at'] ) {
			/* translators: %s: how long ago. */
			$out .= '<br />' . esc_html( sprintf( __( 'Came back %s ago', 'oobj-abandoned-carts' ), human_time_diff( (int) strtotime( $saved['restored_at'] . ' UTC' ) ) ) );
		}
		return $out;
	}
}
