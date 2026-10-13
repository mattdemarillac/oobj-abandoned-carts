<?php
/**
 * Which carts the list shows, how far they got, and pruning.
 *
 * @package oobj-abandoned-carts
 */

declare( strict_types=1 );

class StoreTest extends OOBJ_AC_TestCase {

	public function test_lists_carts_from_the_period_newest_first() {
		$old    = $this->insert_cart( array(), 8 );
		$week   = $this->insert_cart( array(), 6.5 );
		$recent = $this->insert_cart( array(), 0.1 );

		$this->assertSame( array( $recent, $week ), $this->abandoned_ids( 7 ) );
		$this->assertSame( array( $recent ), $this->abandoned_ids( 1 ) );
		$this->assertContains( $old, $this->abandoned_ids( 14 ) );
	}

	public function test_completed_orders_are_left_out_and_unpaid_ones_listed() {
		$ids = array();
		foreach ( array( 'pending', 'failed', 'cancelled', 'checkout-draft', 'processing', 'completed', 'on-hold', 'refunded' ) as $status ) {
			$ids[ $status ] = $this->insert_cart( array( 'order_id' => $this->create_order( $status )->get_id() ) );
		}
		$missing_order = $this->insert_cart( array( 'order_id' => 999999 ) );

		$listed = $this->abandoned_ids();
		sort( $listed );
		$expected = array( $ids['pending'], $ids['failed'], $ids['cancelled'], $ids['checkout-draft'], $missing_order );
		sort( $expected );
		$this->assertSame( $expected, $listed );
	}

	public function test_completed_statuses_can_be_filtered() {
		$id = $this->insert_cart( array( 'order_id' => $this->create_order( 'on-hold' )->get_id() ) );
		add_filter( 'oobj_ac_completed_statuses', fn ( $statuses ) => array_diff( $statuses, array( 'on-hold' ) ) );

		$this->assertSame( array( $id ), $this->abandoned_ids() );
	}

	public function test_empty_carts_are_left_out() {
		$this->insert_cart(
			array(
				'items'      => array(),
				'item_count' => 0,
			)
		);
		$this->assertSame( array(), $this->abandoned_ids() );
	}

	public function test_counts_and_totals_every_cart_not_just_the_page() {
		$this->insert_cart( array( 'total' => 10 ) );
		$this->insert_cart( array( 'total' => 15.5 ) );
		$this->insert_cart( array( 'total' => 4.5 ) );

		$result = $this->store()->abandoned( 7, 2, 1 );
		$this->assertCount( 2, $result['rows'] );
		$this->assertSame( 3, $result['total'] );
		$this->assertEqualsWithDelta( 30.0, $result['value'], 0.001 );
		$this->assertCount( 1, $this->store()->abandoned( 7, 2, 2 )['rows'] );
	}

	public function test_stage_labels() {
		$stage = fn ( array $cart ) => OOBJ_AC_Stage::of(
			$cart + array(
				'checkout_at'  => null,
				'order_id'     => 0,
				'order_status' => null,
			)
		)['key'];

		$this->assertSame( 'cart', $stage( array() ) );
		$this->assertSame( 'checkout', $stage( array( 'checkout_at' => '2026-10-01 00:00:00' ) ) );
		$this->assertSame(
			'checkout',
			$stage(
				array(
					'order_id'     => 5,
					'order_status' => 'checkout-draft',
				)
			)
		);
		$this->assertSame( 'checkout', $stage( array( 'order_id' => 5 ) ) ); // The draft order was cleaned up.
		$this->assertSame(
			'pending',
			$stage(
				array(
					'order_id'     => 5,
					'order_status' => 'pending',
				)
			)
		);
		$this->assertSame(
			'failed',
			$stage(
				array(
					'order_id'     => 5,
					'order_status' => 'failed',
				)
			)
		);
	}

	public function test_prune_deletes_carts_older_than_the_retention() {
		$old  = $this->insert_cart( array(), 31 );
		$kept = $this->insert_cart( array(), 29 );

		$this->assertSame( 1, oobj_abandoned_carts( OOBJ_AC_Cleanup::class )->prune() );
		$this->assertNull( $this->store()->get( $old ) );
		$this->assertNotNull( $this->store()->get( $kept ) );
	}

	public function test_retention_can_be_filtered() {
		$id = $this->insert_cart( array(), 10 );
		add_filter( 'oobj_ac_retention_days', fn () => 7 );

		oobj_abandoned_carts( OOBJ_AC_Cleanup::class )->prune();
		$this->assertNull( $this->store()->get( $id ) );
	}
}
