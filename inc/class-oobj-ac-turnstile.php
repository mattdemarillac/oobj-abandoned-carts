<?php
/**
 * Class OOBJ_AC_Turnstile.
 *
 * @package oobj-abandoned-carts
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Cloudflare Turnstile as the bot check, with the keys the plugin Simple Cloudflare Turnstile keeps (its options
 * cfturnstile_key and cfturnstile_secret). Ready only when both are set; the plugin itself needn't be active.
 * Turnstile's own script loads only once the shopper starts on the form (assets/bot-check-turnstile.js). Verifying
 * sends the answer and the shopper's IP address to Cloudflare.
 */
class OOBJ_AC_Turnstile implements OOBJ_AC_Bot_Check {

	const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

	public function ready(): bool {
		return '' !== $this->site_key() && '' !== $this->secret();
	}

	public function markup(): string {
		return sprintf( '<div class="oobj-ac-save__bot-check" data-oobj-ac-bot-check="turnstile" data-sitekey="%s"></div>', esc_attr( $this->site_key() ) );
	}

	public function enqueue(): void {
		$file = dirname( __DIR__ ) . '/assets/bot-check-turnstile.js';
		wp_enqueue_script(
			'oobj-ac-bot-check',
			plugins_url( 'assets/bot-check-turnstile.js', dirname( __DIR__ ) . '/oobj-abandoned-carts.php' ),
			array(),
			(string) filemtime( $file ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	public function verify( string $answer, string $ip ): bool {
		if ( '' === $answer ) {
			return false;
		}
		$response = wp_remote_post(
			self::VERIFY_URL,
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => $this->secret(),
					'response' => $answer,
					'remoteip' => $ip,
				),
			)
		);
		$data     = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) && ! empty( $data['success'] );
	}

	private function site_key(): string {
		return (string) get_option( 'cfturnstile_key', '' );
	}

	private function secret(): string {
		return (string) get_option( 'cfturnstile_secret', '' );
	}
}
