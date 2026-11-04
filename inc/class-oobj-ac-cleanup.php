<?php
/**
 * Class OOBJ_AC_Cleanup.
 *
 * @package oobj-abandoned-carts
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Deletes carts with no activity for 30 days (filter: oobj_ac_retention_days), daily via Action Scheduler.
 * Shoppers' emails and names are in those rows, so they aren't kept longer than the list needs.
 */
class OOBJ_AC_Cleanup implements OOBJ_AC_Module {

	const HOOK  = 'oobj_ac_prune';
	const GROUP = 'oobj-abandoned-carts';

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
		add_action( self::HOOK, array( $this, 'prune' ) );
		add_action( 'admin_init', array( $this, 'schedule' ) );
	}

	public static function retention_days(): int {
		return max( 1, (int) apply_filters( 'oobj_ac_retention_days', 30 ) );
	}

	/**
	 * Deletes carts past the retention period and emailed carts whose links have expired.
	 *
	 * @return int How many were deleted.
	 */
	public function prune(): int {
		return $this->store->prune( self::retention_days() ) + $this->store->prune_saved();
	}

	public function schedule(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::HOOK, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK, array(), self::GROUP );
		}
	}

	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		}
	}
}
