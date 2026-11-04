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
		delete_option( 'cfturnstile_key' );
		delete_option( 'cfturnstile_secret' );
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

	public function test_markup_carries_the_site_key_escaped() {
		update_option( 'cfturnstile_key', 'key"><script>' );

		$this->assertSame( '<div class="oobj-ac-save__bot-check" data-oobj-ac-bot-check="turnstile" data-sitekey="key&quot;&gt;&lt;script&gt;"></div>', ( new OOBJ_AC_Turnstile() )->markup() );
	}

	public function test_enqueues_its_script() {
		wp_dequeue_script( 'oobj-ac-bot-check' );

		( new OOBJ_AC_Turnstile() )->enqueue();

		$this->assertTrue( wp_script_is( 'oobj-ac-bot-check', 'enqueued' ) );
		$this->assertStringContainsString( 'assets/bot-check-turnstile.js', wp_scripts()->registered['oobj-ac-bot-check']->src );
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
