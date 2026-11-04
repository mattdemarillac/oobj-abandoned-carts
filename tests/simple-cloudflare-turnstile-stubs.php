<?php
/**
 * Stand-ins for Simple Cloudflare Turnstile's whitelist and failsafe checks, which OOBJ_AC_Turnstile calls when that
 * plugin is active (it isn't loaded in the tests). Tests set $GLOBALS['oobj_ac_test_turnstile'].
 *
 * @package oobj-abandoned-carts
 */

declare( strict_types=1 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The plugin's own names.

/**
 * Whether the shopper is on the plugin's whitelist.
 */
function cfturnstile_whitelisted() {
	return ! empty( $GLOBALS['oobj_ac_test_turnstile']['whitelisted'] );
}

/**
 * Whether the plugin finds Cloudflare down.
 */
function cfturnstile_is_cloudflare_down() {
	return ! empty( $GLOBALS['oobj_ac_test_turnstile']['down'] );
}
