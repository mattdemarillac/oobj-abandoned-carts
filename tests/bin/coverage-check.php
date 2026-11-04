<?php
/**
 * Fails unless the PHPUnit coverage report (Clover XML) covers every line of the plugin's PHP, as Jest's
 * coverageThreshold does for assets/js/. Run by composer test:coverage and the GitHub Actions tests:
 * php tests/bin/coverage-check.php [tests/coverage/clover.xml]
 *
 * @package oobj-abandoned-carts
 */

declare( strict_types=1 );

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped -- A command-line script, without WordPress.

$oob_file = $argv[1] ?? dirname( __DIR__ ) . '/coverage/clover.xml';
$oob_xml  = is_readable( $oob_file ) ? simplexml_load_file( $oob_file ) : false;
if ( false === $oob_xml || ! isset( $oob_xml->project->metrics ) ) {
	fwrite( STDERR, "No coverage report at {$oob_file}.\n" );
	exit( 1 );
}

$oob_uncovered = array();
foreach ( $oob_xml->xpath( '//file' ) as $oob_report ) {
	$oob_lines = array();
	foreach ( $oob_report->line as $oob_line ) {
		if ( 'stmt' === (string) $oob_line['type'] && 0 === (int) $oob_line['count'] ) {
			$oob_lines[] = (int) $oob_line['num'];
		}
	}
	if ( $oob_lines ) {
		$oob_uncovered[ (string) $oob_report['name'] ] = $oob_lines;
	}
}

$oob_metrics    = $oob_xml->project->metrics;
$oob_statements = (int) $oob_metrics['statements'];
$oob_covered    = (int) $oob_metrics['coveredstatements'];

if ( $oob_uncovered || $oob_covered < $oob_statements || 0 === $oob_statements ) {
	fwrite( STDERR, "PHP coverage is below 100%: {$oob_covered} of {$oob_statements} lines.\n" );
	foreach ( $oob_uncovered as $oob_name => $oob_lines ) {
		fwrite( STDERR, "  {$oob_name}: lines " . implode( ', ', $oob_lines ) . "\n" );
	}
	exit( 1 );
}

echo "PHP coverage: 100% ({$oob_statements} lines).\n";
