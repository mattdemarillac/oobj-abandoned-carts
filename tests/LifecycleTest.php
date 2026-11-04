<?php
/**
 * Activation, deactivation, the daily clean-up's schedule and the admin page's styles.
 *
 * @package oobj-abandoned-carts
 */

declare( strict_types=1 );

class LifecycleTest extends OOBJ_AC_TestCase {

	public function tear_down() {
		OOBJ_AC_Cleanup::unschedule();
		parent::tear_down();
	}

	public function test_activation_installs_the_tables_and_imports_the_sessions() {
		delete_option( OOBJ_AC_Store::DB_VERSION_OPTION );

		OOBJ_AC_Plugin::activate();

		$this->assertSame( OOBJ_AC_Store::DB_VERSION, get_option( OOBJ_AC_Store::DB_VERSION_OPTION ) );
	}

	public function test_the_cleanup_is_scheduled_once_and_unscheduled_on_deactivation() {
		$cleanup = oobj_abandoned_carts( OOBJ_AC_Cleanup::class );
		$this->assertSame( 10, has_action( 'admin_init', array( $cleanup, 'schedule' ) ) );

		$cleanup->schedule();
		$cleanup->schedule();
		$this->assertCount(
			1,
			as_get_scheduled_actions(
				array(
					'hook'   => OOBJ_AC_Cleanup::HOOK,
					'status' => 'pending',
				),
				'ids'
			)
		);

		OOBJ_AC_Plugin::deactivate();
		$this->assertFalse( as_has_scheduled_action( OOBJ_AC_Cleanup::HOOK, array(), OOBJ_AC_Cleanup::GROUP ) );
	}

	public function test_admin_styles_only_on_its_page() {
		$page = oobj_abandoned_carts( OOBJ_AC_Admin_Page::class );
		wp_dequeue_style( 'oobj-abandoned-carts' );

		$page->enqueue( 'index.php' );
		$this->assertFalse( wp_style_is( 'oobj-abandoned-carts', 'enqueued' ) );

		$page->enqueue( 'woocommerce_page_' . OOBJ_AC_Admin_Page::SLUG );
		$this->assertTrue( wp_style_is( 'oobj-abandoned-carts', 'enqueued' ) );
	}

	public function test_list_table_without_carts_a_name_without_an_account_and_other_columns() {
		$table = new OOBJ_AC_List_Table( $this->store(), 7 );

		ob_start();
		$table->no_items();
		$this->assertSame( 'No unfinished carts in this period.', ob_get_clean() );

		$this->assertSame( '', $table->column_default( array(), 'items' ), 'Only the date columns' );
		$this->assertStringContainsString(
			'<strong>Sam</strong>',
			$table->column_customer(
				array(
					'user_id' => 0,
					'name'    => 'Sam',
					'email'   => '',
				)
			)
		);
	}

	public function test_a_signed_in_customers_name_links_to_their_account() {
		$user = self::factory()->user->create( array( 'display_name' => 'Sam Smith' ) );
		$html = ( new OOBJ_AC_List_Table( $this->store(), 7 ) )->column_customer(
			array(
				'user_id' => $user,
				'name'    => '',
				'email'   => '',
			)
		);

		$this->assertStringContainsString( '<a href="' . esc_url( get_edit_user_link( $user ) ) . '"><strong>Sam Smith</strong></a>', $html );
	}

	public function test_backfill_skips_a_session_that_is_not_an_array() {
		$this->assertNull( ( new OOBJ_AC_Backfill( $this->store() ) )->from_session( 'key', 'not serialized', time() ) );
	}

	public function test_the_list_works_with_the_order_tables() {
		update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );
		update_option( 'woocommerce_feature_custom_order_tables_enabled', 'yes' );
		$this->assertTrue( Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() );
		$id = $this->insert_cart( array( 'order_id' => 999999 ), 1 );

		$result = $this->store()->abandoned( 7 );

		$this->assertSame( array( $id ), array_map( 'intval', array_column( $result['rows'], 'id' ) ), 'An order the tables don\'t have: still listed' );
		update_option( 'woocommerce_custom_orders_table_enabled', 'no' );
	}
}
