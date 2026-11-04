<?php
/**
 * Class OOBJ_AC_Bootstrap_Coverage.
 *
 * @package oobj-abandoned-carts
 */

declare( strict_types=1 );

use PHPUnit\Framework\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestListener;
use PHPUnit\Framework\TestListenerDefaultImplementation;
use SebastianBergmann\CodeCoverage\RawCodeCoverageData;

/**
 * Adds the coverage recorded while the bootstrap loaded WordPress and the plugin (tests/bootstrap.php) to
 * PHPUnit's report, as the test "bootstrap". Only with PCOV (composer test:coverage).
 */
class OOBJ_AC_Bootstrap_Coverage implements TestListener {

	use TestListenerDefaultImplementation;

	public function startTest( Test $test ): void {
		if ( empty( $GLOBALS['oobj_ac_bootstrap_coverage'] ) || ! $test instanceof TestCase ) {
			return;
		}
		$coverage = $test->getTestResultObject() ? $test->getTestResultObject()->getCodeCoverage() : null;
		if ( null === $coverage ) {
			return;
		}
		$coverage->append( RawCodeCoverageData::fromXdebugWithoutPathCoverage( $GLOBALS['oobj_ac_bootstrap_coverage'] ), 'bootstrap' );
		$GLOBALS['oobj_ac_bootstrap_coverage'] = null;
	}
}
