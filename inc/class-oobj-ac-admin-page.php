<?php
/**
 * Class OOBJ_AC_Admin_Page.
 *
 * @package oobj-abandoned-carts
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce → Abandoned carts: the carts from the chosen period (a week by default) that weren't completed.
 */
class OOBJ_AC_Admin_Page implements OOBJ_AC_Module {

	const SLUG = 'oobj-abandoned-carts';

	/**
	 * Periods the page offers, in days.
	 */
	const PERIODS = array( 1, 3, 7, 14, 30 );

	const DEFAULT_DAYS = 7;

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
		add_action( 'admin_menu', array( $this, 'add_menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Abandoned carts', 'oobj-abandoned-carts' ),
			__( 'Abandoned carts', 'oobj-abandoned-carts' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Styles for the page.
	 *
	 * @param string $hook_suffix The admin page.
	 */
	public function enqueue( $hook_suffix ): void {
		if ( 'woocommerce_page_' . self::SLUG === $hook_suffix ) {
			wp_enqueue_style( 'oobj-abandoned-carts', plugins_url( 'assets/css/admin.css', OOBJ_ABANDONED_CARTS_FILE ), array(), OOBJ_ABANDONED_CARTS_VERSION );
		}
	}

	/**
	 * The period in days, from ?days= (one of PERIODS that the store still keeps).
	 */
	public function days(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A read-only filter.
		$days = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : self::DEFAULT_DAYS;
		return in_array( $days, $this->periods(), true ) ? $days : self::DEFAULT_DAYS;
	}

	/**
	 * The periods to offer: no longer than carts are kept.
	 *
	 * @return int[]
	 */
	private function periods(): array {
		$keep = OOBJ_AC_Cleanup::retention_days();
		return array_values( array_filter( self::PERIODS, fn ( $days ) => $days <= $keep ) );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to view this page.', 'oobj-abandoned-carts' ) );
		}
		$days  = $this->days();
		$table = new OOBJ_AC_List_Table( $this->store, $days );
		$table->prepare_items();
		?>
		<div class="wrap oobj-ac">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Abandoned carts', 'oobj-abandoned-carts' ); ?></h1>
			<p class="oobj-ac-intro">
				<?php
				esc_html_e( 'Carts with items in them that didn\'t become a paid (or on-hold) order. Each cart is listed by its last activity. Carts emptied by the shopper aren\'t listed.', 'oobj-abandoned-carts' );
				?>
			</p>

			<ul class="subsubsub">
				<?php foreach ( $this->periods() as $i => $period ) : ?>
					<li>
						<a href="<?php echo esc_url( add_query_arg( 'days', $period, admin_url( 'admin.php?page=' . self::SLUG ) ) ); ?>"<?php echo $period === $days ? ' class="current" aria-current="page"' : ''; ?>>
							<?php
							/* translators: %d: number of days. */
							echo esc_html( 1 === $period ? __( 'Last 24 hours', 'oobj-abandoned-carts' ) : sprintf( __( 'Last %d days', 'oobj-abandoned-carts' ), $period ) );
							?>
						</a><?php echo $i < count( $this->periods() ) - 1 ? ' |' : ''; ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<p class="oobj-ac-summary">
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: 1: number of carts, 2: their combined value. */
						_n( '<strong>%1$s</strong> abandoned cart worth <strong>%2$s</strong>', '<strong>%1$s</strong> abandoned carts worth <strong>%2$s</strong>', $table->total_carts(), 'oobj-abandoned-carts' ),
						number_format_i18n( $table->total_carts() ),
						wc_price( $table->total_value() )
					)
				);
				?>
			</p>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<input type="hidden" name="days" value="<?php echo esc_attr( (string) $days ); ?>" />
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}
}
