<?php
/**
 * OOBJ_AC_Turnstile: Cloudflare Turnstile as the bot check, with Simple Cloudflare Turnstile's keys. Cloudflare is
 * mocked.
 *
 * @package oobj-abandoned-carts
 */

declare( strict_types=1 );

class TurnstileTest extends OOBJ_AC_TestCase {

	/**
	 * The request sent to Cloudflare.
	 *
	 * @var array|null
	 */
	private $sent = null;

	public function tear_down() {
		foreach ( array( 'key', 'secret', 'appearance', 'theme', 'size', 'language', 'refresh_timeout', 'failover', 'failsafe_type' ) as $name ) {
			delete_option( "cfturnstile_$name" );
		}
		$GLOBALS['oobj_ac_test_turnstile'] = array();
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	private function cloudflare_answers( bool $success ): void {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $success ) {
				$this->assertSame( OOBJ_AC_Turnstile::VERIFY_URL, $url );
				$this->sent = $args['body'];
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => (string) wp_json_encode( array( 'success' => $success ) ),
				);
			},
			10,
			3
		);
	}

	public function test_ready_only_with_both_keys() {
		$turnstile = new OOBJ_AC_Turnstile();
		$this->assertFalse( $turnstile->ready() );

		update_option( 'cfturnstile_key', 'site-key' );
		$this->assertFalse( $turnstile->ready() );

		update_option( 'cfturnstile_secret', 'secret' );
		$this->assertTrue( $turnstile->ready() );
	}

	public function test_markup_carries_the_plugins_widget_settings() {
		update_option( 'cfturnstile_key', 'site-key' );
		update_option( 'cfturnstile_appearance', 'interaction-only' );
		update_option( 'cfturnstile_theme', 'light' );
		update_option( 'cfturnstile_size', 'compact' );
		update_option( 'cfturnstile_language', 'en' );
		update_option( 'cfturnstile_refresh_timeout', 'manual' );

		$this->assertSame(
			'<div class="oobj-ac-save__bot-check" data-oobj-ac-bot-check="turnstile" data-sitekey="site-key" data-appearance="interaction-only" data-theme="light" data-size="compact" data-language="en" data-refresh-timeout="manual"></div>',
			( new OOBJ_AC_Turnstile() )->markup()
		);
	}

	public function test_markup_defaults_and_escaping() {
		update_option( 'cfturnstile_key', 'key"x' );

		$this->assertSame(
			'<div class="oobj-ac-save__bot-check" data-oobj-ac-bot-check="turnstile" data-sitekey="key&quot;x" data-appearance="always" data-theme="auto" data-size="normal" data-language="auto" data-refresh-timeout="auto"></div>',
			( new OOBJ_AC_Turnstile() )->markup(),
			'Turnstile\'s defaults for settings left empty'
		);
	}

	public function test_no_check_for_whitelisted_shoppers_or_while_cloudflare_is_down() {
		update_option( 'cfturnstile_key', 'site-key' );
		update_option( 'cfturnstile_secret', 'secret' );
		$turnstile = new OOBJ_AC_Turnstile();
		$this->assertTrue( $turnstile->ready() );

		$GLOBALS['oobj_ac_test_turnstile'] = array( 'whitelisted' => true );
		$this->assertFalse( $turnstile->ready(), 'On the plugin\'s whitelist (e.g. signed in)' );

		$GLOBALS['oobj_ac_test_turnstile'] = array( 'down' => true );
		$this->assertTrue( $turnstile->ready(), 'The failsafe is off' );
		update_option( 'cfturnstile_failover', 'on' );
		$this->assertFalse( $turnstile->ready(), 'Failsafe on, allowing forms through' );
		update_option( 'cfturnstile_failsafe_type', 'recaptcha' );
		$this->assertTrue( $turnstile->ready(), 'A failsafe that doesn\'t just allow' );
	}

	public function test_enqueues_its_script() {
		wp_dequeue_script( 'oobj-ac-bot-check' );

		( new OOBJ_AC_Turnstile() )->enqueue();

		$this->assertTrue( wp_script_is( 'oobj-ac-bot-check', 'enqueued' ) );
		$this->assertStringContainsString( 'assets/adapters/turnstile.js', wp_scripts()->registered['oobj-ac-bot-check']->src );
		$this->assertSame( 'defer', wp_scripts()->get_data( 'oobj-ac-bot-check', 'strategy' ) );
	}

	public function test_verifies_the_answer_with_cloudflare() {
		update_option( 'cfturnstile_secret', 'secret' );
		$turnstile = new OOBJ_AC_Turnstile();

		$this->assertFalse( $turnstile->verify( '', '203.0.113.5' ), 'No answer: Cloudflare isn\'t asked' );
		$this->assertNull( $this->sent );

		$this->cloudflare_answers( true );
		$this->assertTrue( $turnstile->verify( 'answer', '203.0.113.5' ) );
		$this->assertSame(
			array(
				'secret'   => 'secret',
				'response' => 'answer',
				'remoteip' => '203.0.113.5',
			),
			$this->sent
		);

		remove_all_filters( 'pre_http_request' );
		$this->cloudflare_answers( false );
		$this->assertFalse( $turnstile->verify( 'answer', '203.0.113.5' ) );
	}
}
