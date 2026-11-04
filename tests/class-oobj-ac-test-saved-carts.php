<?php
/**
 * Class OOBJ_AC_Test_Saved_Carts.
 *
 * @package oobj-abandoned-carts
 */

declare( strict_types=1 );

/**
 * OOBJ_AC_Saved_Carts, stopping with an exception instead of exit, so tests can follow a redirect.
 */
class OOBJ_AC_Test_Saved_Carts extends OOBJ_AC_Saved_Carts {
	protected function stop(): void {
		throw new OOBJ_AC_Test_Stopped();
	}
}
