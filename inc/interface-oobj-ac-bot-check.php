<?php
/**
 * Interface OOBJ_AC_Bot_Check.
 *
 * @package oobj-abandoned-carts
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * A check that "Email me my cart" was sent by a person (a CAPTCHA or similar), on top of the trap field and the
 * limits, which always apply. OOBJ_AC_Turnstile is one; the filter oobj_ac_bot_check picks the one used, or none.
 *
 * In the browser, the check's script defines window.oobjAcBotCheck with start( element ) (shows the check in the
 * element markup() printed; called when the shopper starts on the form), token() (the answer to send, or '') and
 * reset() (after each try). The form sends token() as the bot_check parameter, and verify() checks it.
 */
interface OOBJ_AC_Bot_Check {

	/**
	 * Whether it's set up (has its keys) and should be used.
	 */
	public function ready(): bool;

	/**
	 * What goes in the form: an element with a data-oobj-ac-bot-check attribute, for start().
	 */
	public function markup(): string;

	/**
	 * Enqueues its script, which defines window.oobjAcBotCheck. On the cart and checkout pages.
	 */
	public function enqueue(): void;

	/**
	 * Whether the answer the browser sent passes.
	 *
	 * @param string $answer The answer (token()).
	 * @param string $ip     The shopper's IP address.
	 */
	public function verify( string $answer, string $ip ): bool;
}
