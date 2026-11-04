<?php
/**
 * Class OOBJ_AC_Store.
 *
 * @package oobj-abandoned-carts
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * The carts table: one row per cart, from its first item until it's emptied, its order is paid, or it's pruned.
 *
 * A row is an array with the table's columns; `items` is decoded to a list of
 * array{product_id: int, variation_id: int, name: string, quantity: int, line_total: float}. Dates are GMT.
 * Rows from the abandoned() query also have `order_status` (without `wc-`, or null when there's no order).
 */
class OOBJ_AC_Store {

	const DB_VERSION        = '2';
	const DB_VERSION_OPTION = 'oobj_ac_db_version';

	/**
	 * Columns that insert() and update() write.
	 */
	const COLUMNS = array( 'cart_key', 'user_id', 'email', 'name', 'items', 'item_count', 'total', 'currency', 'order_id', 'created_at', 'updated_at', 'checkout_at' );

	public function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'oobj_ac_carts';
	}

	/**
	 * Carts shoppers emailed themselves (OOBJ_AC_Saved_Carts): the items, and a hash of the link's token.
	 */
	public function saved_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'oobj_ac_saved';
	}

	public function maybe_install(): void {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			$this->install();
		}
	}

	public function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			"CREATE TABLE {$this->table()} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				cart_key varchar(64) NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				email varchar(200) NOT NULL DEFAULT '',
				name varchar(200) NOT NULL DEFAULT '',
				items longtext NOT NULL,
				item_count int(10) unsigned NOT NULL DEFAULT 0,
				total decimal(19,4) NOT NULL DEFAULT 0,
				currency char(3) NOT NULL DEFAULT '',
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				checkout_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY cart_key (cart_key),
				KEY updated_at (updated_at),
				KEY order_id (order_id)
			) {$wpdb->get_charset_collate()};"
		);
		dbDelta(
			"CREATE TABLE {$this->saved_table()} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				cart_id bigint(20) unsigned NOT NULL DEFAULT 0,
				token_hash char(64) NOT NULL,
				email varchar(200) NOT NULL,
				items longtext NOT NULL,
				created_at datetime NOT NULL,
				expires_at datetime NOT NULL,
				restored_at datetime DEFAULT NULL,
				restores int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY token_hash (token_hash),
				KEY email (email),
				KEY cart_id (cart_id),
				KEY expires_at (expires_at)
			) {$wpdb->get_charset_collate()};"
		);
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	public function get( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table(), $id ), ARRAY_A );
		return $row ? $this->decode( $row ) : null;
	}

	/**
	 * The newest cart with this key whose order (if any) isn't completed.
	 *
	 * @param string $cart_key WooCommerce's customer ID for the session.
	 */
	public function find_open( string $cart_key ): ?array {
		global $wpdb;
		list( $join, $status ) = $this->order_join();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT c.*, $status AS order_status FROM %i c $join WHERE c.cart_key = %s AND {$this->not_completed( $status )} ORDER BY c.id DESC LIMIT 1", $this->table(), $cart_key ), ARRAY_A );
		return $row ? $this->decode( $row ) : null;
	}

	/**
	 * Whether a cart is open: it exists and its order (if any) isn't completed.
	 *
	 * @param int $id The cart's ID.
	 */
	public function is_open( int $id ): bool {
		global $wpdb;
		list( $join, $status ) = $this->order_join();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT c.id FROM %i c $join WHERE c.id = %d AND {$this->not_completed( $status )}", $this->table(), $id ) );
	}

	/**
	 * Carts active in the last $days days whose order (if any) isn't completed, most recent first.
	 *
	 * @param int $days     How many days back, by last activity.
	 * @param int $per_page Page size.
	 * @param int $page     1-based page.
	 * @return array{rows: array[], total: int, value: float} Rows on the page, how many carts in all, and their combined total.
	 */
	public function abandoned( int $days, int $per_page = 50, int $page = 1 ): array {
		global $wpdb;
		list( $join, $status ) = $this->order_join();
		$since                 = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$where                 = "c.updated_at >= %s AND c.item_count > 0 AND {$this->not_completed( $status )}";
		// $where holds the %s for $since.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$sums = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS carts, COALESCE(SUM(c.total), 0) AS value FROM %i c $join WHERE $where", $this->table(), $since ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.*, $status AS order_status FROM %i c $join WHERE $where ORDER BY c.updated_at DESC, c.id DESC LIMIT %d OFFSET %d",
				$this->table(),
				$since,
				$per_page,
				max( 0, $page - 1 ) * $per_page
			),
			ARRAY_A
		);
		// phpcs:enable
		return array(
			'rows'  => array_map( array( $this, 'decode' ), $rows ),
			'total' => (int) $sums->carts,
			'value' => (float) $sums->value,
		);
	}

	/**
	 * Add a cart.
	 *
	 * @param array $data Columns (see COLUMNS); `items` as a list.
	 * @return int The new cart's ID.
	 */
	public function insert( array $data ): int {
		global $wpdb;
		$now  = current_time( 'mysql', true );
		$data = $this->encode( $data ) + array(
			'items'      => '[]',
			'created_at' => $now,
			'updated_at' => $now,
		);
		$wpdb->insert( $this->table(), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return (int) $wpdb->insert_id;
	}

	/**
	 * Change a cart.
	 *
	 * @param int   $id   The cart's ID.
	 * @param array $data Columns to change (see COLUMNS); `items` as a list.
	 */
	public function update( int $id, array $data ): void {
		global $wpdb;
		$data = $this->encode( $data );
		if ( $data ) {
			$wpdb->update( $this->table(), $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}
	}

	public function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( $this->table(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	public function exists_for_key( string $cart_key ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE cart_key = %s LIMIT 1', $this->table(), $cart_key ) );
	}

	/**
	 * Delete carts with no activity for $days days.
	 *
	 * @param int $days Age in days.
	 * @return int How many were deleted.
	 */
	public function prune( int $days ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE updated_at < %s', $this->table(), gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
	}

	/**
	 * The JOIN to the order's row and the SQL expression for its status (without `wc-`), for HPOS or posts.
	 *
	 * @return array{0: string, 1: string}
	 */
	/**
	 * Saves an emailed cart.
	 *
	 * @param array{cart_id: int, token: string, email: string, items: array, days: int} $saved The cart.
	 * @return int Its ID.
	 */
	public function insert_saved( array $saved ): int {
		global $wpdb;
		$now = time();
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$this->saved_table(),
			array(
				'cart_id'    => $saved['cart_id'],
				'token_hash' => self::token_hash( $saved['token'] ),
				'email'      => $saved['email'],
				'items'      => (string) wp_json_encode( $saved['items'] ),
				'created_at' => gmdate( 'Y-m-d H:i:s', $now ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', $now + $saved['days'] * DAY_IN_SECONDS ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * The emailed cart a link's token opens, unless it has expired.
	 *
	 * @param string $token The token in the link.
	 */
	public function find_saved( string $token ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT * FROM %i WHERE token_hash = %s AND expires_at > %s', $this->saved_table(), self::token_hash( $token ), gmdate( 'Y-m-d H:i:s' ) ),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		$row['id']    = (int) $row['id'];
		$row['items'] = json_decode( (string) $row['items'], true );
		$row['items'] = is_array( $row['items'] ) ? $row['items'] : array();
		return $row;
	}

	/**
	 * Records that an emailed cart's link was used.
	 *
	 * @param int $id The emailed cart.
	 */
	public function mark_restored( int $id ): void {
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'UPDATE %i SET restored_at = %s, restores = restores + 1 WHERE id = %d', $this->saved_table(), gmdate( 'Y-m-d H:i:s' ), $id )
		);
	}

	/**
	 * How many carts were emailed to an address in the last day.
	 *
	 * @param string $email The address.
	 */
	public function saved_today( string $email ): int {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE email = %s AND created_at > %s', $this->saved_table(), $email, gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) )
		);
	}

	/**
	 * When a cart was emailed and its link last used: for the admin list.
	 *
	 * @param int[] $cart_ids Carts.
	 * @return array<int, array{saved_at: string, restored_at: ?string}>
	 */
	public function saved_for( array $cart_ids ): array {
		global $wpdb;
		$cart_ids = array_filter( array_map( 'intval', $cart_ids ) );
		if ( ! $cart_ids ) {
			return array();
		}
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT cart_id, MAX(created_at) AS saved_at, MAX(restored_at) AS restored_at FROM %i WHERE cart_id IN (' . implode( ',', array_fill( 0, count( $cart_ids ), '%d' ) ) . ') GROUP BY cart_id', array_merge( array( $this->saved_table() ), $cart_ids ) ), // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- One %d per ID.
			ARRAY_A
		);
		$saved = array();
		foreach ( $rows as $row ) {
			$saved[ (int) $row['cart_id'] ] = array(
				'saved_at'    => (string) $row['saved_at'],
				'restored_at' => $row['restored_at'] ? (string) $row['restored_at'] : null,
			);
		}
		return $saved;
	}

	/**
	 * Deletes emailed carts whose links have expired.
	 */
	public function prune_saved(): int {
		global $wpdb;
		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'DELETE FROM %i WHERE expires_at <= %s', $this->saved_table(), gmdate( 'Y-m-d H:i:s' ) )
		);
	}

	/**
	 * Only the hash is stored, so the table can't be used to open someone's cart.
	 *
	 * @param string $token The token in the link.
	 */
	public static function token_hash( string $token ): string {
		return hash( 'sha256', $token );
	}

	private function order_join(): array {
		global $wpdb;
		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$orders = OrderUtil::get_table_for_orders();
			return array( "LEFT JOIN $orders o ON o.id = c.order_id AND c.order_id > 0", "REPLACE(o.status, 'wc-', '')" );
		}
		return array( "LEFT JOIN {$wpdb->posts} o ON o.ID = c.order_id AND c.order_id > 0 AND o.post_type = 'shop_order'", "REPLACE(o.post_status, 'wc-', '')" );
	}

	/**
	 * SQL: the order status expression is null (no order) or not a completed status.
	 *
	 * @param string $status The status expression from order_join().
	 */
	private function not_completed( string $status ): string {
		$statuses = implode( ',', array_map( fn ( $s ) => "'" . esc_sql( $s ) . "'", OOBJ_AC_Stage::completed_statuses() ) );
		return "( $status IS NULL OR $status NOT IN ( $statuses ) )";
	}

	private function encode( array $data ): array {
		$data = array_intersect_key( $data, array_flip( self::COLUMNS ) );
		if ( isset( $data['items'] ) ) {
			$data['items'] = (string) wp_json_encode( array_values( $data['items'] ) );
		}
		return $data;
	}

	private function decode( array $row ): array {
		foreach ( array( 'id', 'user_id', 'item_count', 'order_id' ) as $key ) {
			$row[ $key ] = (int) $row[ $key ];
		}
		$row['total'] = (float) $row['total'];
		$items        = json_decode( (string) $row['items'], true );
		$row['items'] = is_array( $items ) ? $items : array();
		return $row;
	}
}
