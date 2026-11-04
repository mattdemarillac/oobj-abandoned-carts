<?php
/**
 * Class OOBJ_AC_Turnstile.
 *
 * @package oobj-abandoned-carts
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Cloudflare Turnstile as the bot check, set up and configured in the plugin Simple Cloudflare Turnstile, whose
 * settings it follows:
 *
 * - its keys (cfturnstile_key, cfturnstile_secret): ready only when both are set;
 * - the widget's appearance (Always, or Interaction Only: shown only when Cloudflare needs the shopper to do
 *   something), theme, size, language and refresh timeout;
 * - its whitelist (signed-in users, IP addresses, user agents): whitelisted shoppers get no check, in the form or on
 *   the server (cfturnstile_whitelisted(), while that plugin is active);
 * - its failsafe set to "allow": no check while it finds Cloudflare down.
 *
 * Turnstile's own script loads only once the shopper starts on the form (assets/adapters/turnstile.js). Verifying
 * sends the answer and the shopper's IP address to Cloudflare.
 */
class OOBJ_AC_Turnstile implements OOBJ_AC_Bot_Check {

	const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

	public function ready(): bool {
		return '' !== $this->option( 'key' ) && '' !== $this->option( 'secret' ) && ! $this->exempt();
	}

	public function markup(): string {
		$attributes = array(
			'sitekey'         => $this->option( 'key' ),
			'appearance'      => $this->option( 'appearance', 'always' ),
			'theme'           => $this->option( 'theme', 'auto' ),
			'size'            => $this->option( 'size', 'normal' ),
			'language'        => $this->option( 'language', 'auto' ),
			'refresh-timeout' => $this->option( 'refresh_timeout', 'auto' ),
		);
		$html       = '<div class="oobj-ac-save__bot-check" data-oobj-ac-bot-check="turnstile"';
		foreach ( $attributes as $name => $value ) {
			$html .= sprintf( ' data-%s="%s"', $name, esc_attr( $value ) );
		}
		return $html . '></div>';
	}

	public function enqueue(): void {
		wp_enqueue_script(
			'oobj-ac-bot-check',
			plugins_url( 'assets/adapters/turnstile.js', OOBJ_ABANDONED_CARTS_FILE ),
			array(),
			(string) filemtime( dirname( OOBJ_ABANDONED_CARTS_FILE ) . '/assets/adapters/turnstile.js' ),
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
					'secret'   => $this->option( 'secret' ),
					'response' => $answer,
					'remoteip' => $ip,
				),
			)
		);
		$data     = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) && ! empty( $data['success'] );
	}

	/**
	 * Whether this shopper gets no check: on Simple Cloudflare Turnstile's whitelist, or Cloudflare is down and its
	 * failsafe allows forms through.
	 */
	private function exempt(): bool {
		if ( function_exists( 'cfturnstile_whitelisted' ) && cfturnstile_whitelisted() ) {
			return true;
		}
		return get_option( 'cfturnstile_failover' )
			&& 'allow' === $this->option( 'failsafe_type', 'allow' )
			&& function_exists( 'cfturnstile_is_cloudflare_down' ) && cfturnstile_is_cloudflare_down();
	}

	/**
	 * One of Simple Cloudflare Turnstile's settings (cfturnstile_<name>).
	 *
	 * @param string $name     The setting, without cfturnstile_.
	 * @param string $fallback When it's empty.
	 */
	private function option( string $name, string $fallback = '' ): string {
		$value = sanitize_text_field( (string) get_option( "cfturnstile_$name", '' ) );
		return '' === $value ? $fallback : $value;
	}
}
