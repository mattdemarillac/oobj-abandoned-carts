<?php
/**
 * OOBJ_AC_Saved_Carts: "Email me my cart", the email, and the link that puts the cart back.
 *
 * @package oobj-abandoned-carts
 */

declare( strict_types=1 );

class SavedCartsTest extends OOBJ_AC_TestCase {

	/**
	 * Where the last redirect went.
	 *
	 * @var string
	 */
	private $redirect = '';

	/**
	 * The cart and checkout pages, for is_cart() and is_checkout(): made once for the class, outside each
	 * test's transaction.
	 */
	public static function wpSetUpBeforeClass() {
		delete_option( 'woocommerce_cart_page_id' );
		delete_option( 'woocommerce_checkout_page_id' );
		WC_Install::create_pages();
	}

	public static function wpTearDownAfterClass() {
		foreach ( array( 'cart', 'checkout', 'myaccount', 'shop', 'refund_returns' ) as $page ) {
			wp_delete_post( wc_get_page_id( $page ), true );
			delete_option( "woocommerce_{$page}_page_id" );
		}
	}

	public function set_up() {
		parent::set_up();
		reset_phpmailer_instance();
		delete_option( 'cfturnstile_secret' );
		delete_option( 'cfturnstile_key' );
		WC()->session->set( OOBJ_AC_Saved_Carts::RESTORED_KEY, null );
		wc_clear_notices();
		add_filter(
			'wp_redirect',
			function ( $location ) {
				$this->redirect = $location;
				return false;
			}
		);
	}

	public function tear_down() {
		$GLOBALS['post'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Left by a test on the cart page.
		unset( $_GET[ OOBJ_AC_Saved_Carts::QUERY_VAR ] );
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'wp_redirect' );
		remove_all_filters( 'oobj_ac_bot_check' );
		wc_clear_notices();
		parent::tear_down();
	}

	private function module( ?OOBJ_AC_Bot_Check $bot_check = null ): OOBJ_AC_Test_Saved_Carts {
		return new OOBJ_AC_Test_Saved_Carts( $this->store(), $bot_check ?? new OOBJ_AC_Turnstile() );
	}

	private function request( array $params ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/oobj-ac/v1/saved-carts' );
		$request->set_body_params( $params );
		return rest_do_request( $request );
	}

	private function sent(): array {
		return tests_retrieve_phpmailer_instance()->mock_sent;
	}

	private function saved_rows(): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $this->store()->saved_table() ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private function turnstile_answers( bool $success ): void {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $success ) {
				if ( false === strpos( $url, 'challenges.cloudflare.com' ) ) {
					return $pre;
				}
				$this->assertSame( 'secret', $args['body']['secret'] );
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => (string) wp_json_encode( array( 'success' => $success ) ),
				);
			},
			10,
			3
		);
	}

	public function test_registers_its_route_hooks_and_forms() {
		$module = $this->module();
		$module->register();

		$this->assertSame( 10, has_action( 'rest_api_init', array( $module, 'register_route' ) ) );
		$this->assertSame( 10, has_action( 'template_redirect', array( $module, 'restore' ) ) );
		$this->assertSame( 10, has_filter( 'render_block_woocommerce/cart', array( $module, 'form' ) ) );
		$this->assertSame( 10, has_filter( 'render_block_woocommerce/checkout', array( $module, 'checkout_form' ) ) );
		$this->assertSame( 10, has_action( 'wp_enqueue_scripts', array( $module, 'enqueue' ) ) );
		$this->assertArrayHasKey( '/oobj-ac/v1/saved-carts', rest_get_server()->get_routes() );
	}

	public function test_emails_the_cart_with_a_link_back() {
		$this->add_to_cart( $this->create_product( 'Titanium Hoop', '12' ), 2 );
		oobj_abandoned_carts( OOBJ_AC_Tracker::class )->save_cart();
		$cart = $this->only_cart();

		$response = $this->request( array( 'email' => 'sam@example.org' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'sent' => true ), $response->get_data() );
		$mail = $this->sent();
		$this->assertCount( 1, $mail );
		$this->assertSame( 'sam@example.org', $mail[0]['to'][0][0] );
		$this->assertStringContainsString( 'Your saved cart at', $mail[0]['subject'] );
		$this->assertStringContainsString( 'Titanium Hoop', $mail[0]['body'] );
		$this->assertStringContainsString( 'Back to my cart', $mail[0]['body'] );
		$this->assertStringContainsString( 'The link works for 30 days.', $mail[0]['body'] );
		$this->assertSame( 1, preg_match( '/saved-cart=([a-f0-9]{32})/', $mail[0]['body'], $token ) );

		$rows = $this->saved_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( (string) $cart['id'], $rows[0]['cart_id'] );
		$this->assertSame( OOBJ_AC_Store::token_hash( $token[1] ), $rows[0]['token_hash'], 'Only the hash is stored' );
		$this->assertStringNotContainsString( $token[1], (string) wp_json_encode( $rows ) );
		$this->assertSame( 'sam@example.org', $this->only_cart()['email'], 'The cart\'s record gets the address' );
		$this->assertSame( 2, $this->store()->find_saved( $token[1] )['items'][0]['quantity'] );
	}

	public function test_keeps_the_address_the_cart_already_had() {
		$this->add_to_cart( $this->create_product() );
		oobj_abandoned_carts( OOBJ_AC_Tracker::class )->save_cart();
		$this->store()->update( $this->only_cart()['id'], array( 'email' => 'known@example.org' ) );

		$this->request( array( 'email' => 'sam@example.org' ) );

		$this->assertSame( 'known@example.org', $this->only_cart()['email'] );
	}

	public function test_a_filled_in_trap_field_sends_nothing_but_says_it_did() {
		$this->add_to_cart( $this->create_product() );

		$response = $this->request(
			array(
				'email'   => 'sam@example.org',
				'website' => 'http://spam.example',
			)
		);

		$this->assertSame( array( 'sent' => true ), $response->get_data() );
		$this->assertCount( 0, $this->sent() );
		$this->assertCount( 0, $this->saved_rows() );
	}

	public function test_refuses_an_invalid_address_or_an_empty_cart() {
		$response = $this->request( array( 'email' => 'not an email' ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'oobj_ac_email', $response->get_data()['code'] );

		$response = $this->request( array( 'email' => 'sam@example.org' ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'oobj_ac_empty', $response->get_data()['code'] );
		$this->assertCount( 0, $this->sent() );
	}

	public function test_turnstile_when_it_has_keys() {
		update_option( 'cfturnstile_key', 'site-key' );
		update_option( 'cfturnstile_secret', 'secret' );
		$this->add_to_cart( $this->create_product() );

		$response = $this->request( array( 'email' => 'sam@example.org' ) );
		$this->assertSame( 'oobj_ac_bot_check', $response->get_data()['code'], 'No answer from the widget' );

		$this->turnstile_answers( false );
		$response = $this->request(
			array(
				'email'     => 'sam@example.org',
				'bot_check' => 'token',
			)
		);
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'oobj_ac_bot_check', $response->get_data()['code'] );

		remove_all_filters( 'pre_http_request' );
		$this->turnstile_answers( true );
		$response = $this->request(
			array(
				'email'     => 'sam@example.org',
				'bot_check' => 'token',
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $this->sent() );
	}

	public function test_another_bot_check_through_the_filter_or_none() {
		$check = new OOBJ_AC_Test_Bot_Check();
		add_filter( 'oobj_ac_bot_check', fn() => $check );
		$this->add_to_cart( $this->create_product() );

		$this->assertSame( $check, $this->module()->bot_check() );
		$this->assertSame( 400, $this->request( array( 'email' => 'sam@example.org' ) )->get_status() );
		$response = $this->request(
			array(
				'email'     => 'sam@example.org',
				'bot_check' => 'human',
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( '', 'human' ), $check->verified, 'No answer, then a passing one' );
		$this->assertStringContainsString( '<div data-oobj-ac-bot-check="test"></div>', $this->module()->form( '' ) );

		$check->is_ready = false;
		$this->assertNull( $this->module()->bot_check(), 'One that isn\'t ready isn\'t used' );

		remove_all_filters( 'oobj_ac_bot_check' );
		add_filter( 'oobj_ac_bot_check', '__return_null' );
		update_option( 'cfturnstile_key', 'site-key' );
		update_option( 'cfturnstile_secret', 'secret' );
		$this->assertNull( $this->module()->bot_check(), 'Turned off' );
		$this->assertSame( 200, $this->request( array( 'email' => 'other@example.org' ) )->get_status() );
	}

	public function test_no_bot_check_without_one_set_up() {
		$this->assertNull( ( new OOBJ_AC_Saved_Carts( $this->store() ) )->bot_check(), 'None given' );
		$this->assertNull( $this->module()->bot_check(), 'Turnstile without keys' );
	}

	public function test_limits_emails_per_address_and_per_ip() {
		$this->add_to_cart( $this->create_product() );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand( 1, 250 );

		for ( $i = 0; $i < OOBJ_AC_Saved_Carts::MAX_PER_EMAIL; $i++ ) {
			$this->assertSame( 200, $this->request( array( 'email' => 'sam@example.org' ) )->get_status() );
		}
		$response = $this->request( array( 'email' => 'sam@example.org' ) );
		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'oobj_ac_limit', $response->get_data()['code'] );

		for ( $i = OOBJ_AC_Saved_Carts::MAX_PER_EMAIL; $i < OOBJ_AC_Saved_Carts::MAX_PER_IP; $i++ ) {
			$this->assertSame( 200, $this->request( array( 'email' => "other$i@example.org" ) )->get_status() );
		}
		$this->assertSame( 429, $this->request( array( 'email' => 'new@example.org' ) )->get_status(), 'The IP\'s hour is used up' );
		$this->assertCount( OOBJ_AC_Saved_Carts::MAX_PER_IP, $this->sent() );
	}

	public function test_the_email_leaves_out_products_since_deleted() {
		$product = $this->create_product( 'Gone' );
		$method  = new ReflectionMethod( OOBJ_AC_Saved_Carts::class, 'send' );
		$method->setAccessible( true );
		$items = array(
			array(
				'product_id'   => $product->get_id(),
				'variation_id' => 0,
				'quantity'     => 1,
			),
		);
		$product->delete( true );

		$method->invoke( $this->module(), 'sam@example.org', $items, 'abc' );

		$this->assertStringNotContainsString( 'Gone', $this->sent()[0]['body'] );
	}

	/**
	 * Saves a cart with the given items under a known token.
	 */
	private function saved( array $items, string $token = 'feedfacefeedfacefeedfacefeedface' ): string {
		$this->store()->insert_saved(
			array(
				'cart_id' => 0,
				'token'   => $token,
				'email'   => 'sam@example.org',
				'items'   => $items,
				'days'    => 30,
			)
		);
		return $token;
	}

	private function restore( string $token ): void {
		$this->go_to( add_query_arg( OOBJ_AC_Saved_Carts::QUERY_VAR, $token, get_permalink( wc_get_page_id( 'cart' ) ) ) );
		$GLOBALS['post']                        = get_post( wc_get_page_id( 'cart' ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- As the page's request sets it.
		$_GET[ OOBJ_AC_Saved_Carts::QUERY_VAR ] = $token;
		try {
			$this->module()->restore();
			$this->fail( 'Should redirect' );
		} catch ( OOBJ_AC_Test_Stopped $stopped ) {
			$this->assertSame( wc_get_cart_url(), $this->redirect, 'To the cart, without the token' );
		}
	}

	private function notices( string $type ): array {
		return array_column( wc_get_notices( $type ), 'notice' );
	}

	public function test_the_link_puts_what_is_for_sale_back_once() {
		$hoop  = $this->create_product( 'Hoop' );
		$gone  = $this->create_product( 'Sold out stud' );
		$token = $this->saved(
			array(
				array(
					'product_id'   => $hoop->get_id(),
					'variation_id' => 0,
					'variation'    => array(),
					'quantity'     => 2,
					'name'         => 'Hoop',
				),
				array(
					'product_id'   => $gone->get_id(),
					'variation_id' => 0,
					'variation'    => array(),
					'quantity'     => 1,
					'name'         => 'Sold out stud',
				),
			)
		);
		$gone->set_stock_status( 'outofstock' );
		$gone->save();

		$this->restore( $token );

		$this->assertSame( 2, WC()->cart->get_cart_contents_count() );
		$this->assertSame( array( 'Your saved cart is back.' ), $this->notices( 'success' ) );
		$this->assertSame( array( 'No longer available: Sold out stud.' ), $this->notices( 'notice' ) );
		$row = $this->saved_rows()[0];
		$this->assertNotNull( $row['restored_at'] );
		$this->assertSame( '1', $row['restores'] );

		wc_clear_notices();
		$this->restore( $token );
		$this->assertSame( 2, WC()->cart->get_cart_contents_count(), 'Not added twice in one session' );
		$this->assertSame( array(), $this->notices( 'success' ) );
	}

	public function test_an_expired_or_unknown_link_says_so() {
		$this->restore( 'nosuchtoken' );

		$this->assertSame( array( 'That saved cart link has expired.' ), $this->notices( 'notice' ) );
		$this->assertTrue( WC()->cart->is_empty() );
	}

	public function test_does_nothing_without_a_token_or_away_from_the_cart() {
		$this->go_to( get_permalink( wc_get_page_id( 'cart' ) ) );
		$GLOBALS['post'] = get_post( wc_get_page_id( 'cart' ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- As the page's request sets it.
		$this->module()->restore();

		$this->go_to( home_url( '/' ) );
		$_GET[ OOBJ_AC_Saved_Carts::QUERY_VAR ] = 'feedfacefeedfacefeedfacefeedface';
		$this->module()->restore();

		$this->assertSame( '', $this->redirect );
	}

	public function test_the_form_under_the_cart_and_collapsed_under_the_checkout() {
		$module = $this->module();
		$this->assertSame( '<div>cart</div>', $module->form( '<div>cart</div>' ), 'Nothing for an empty cart' );

		update_option( 'cfturnstile_key', 'site-key' );
		update_option( 'cfturnstile_secret', 'secret' );
		$this->add_to_cart( $this->create_product() );
		$cart = $module->form( '<div>cart</div>' );

		$this->assertStringStartsWith( '<div>cart</div>', $cart );
		$this->assertStringContainsString( '<section class="oobj-ac-save" id="save-cart" aria-labelledby="oobj-ac-save-title">', $cart );
		$this->assertStringContainsString( '<label class="oobj-ac-save__label" for="oobj-ac-email">Email</label>', $cart );
		$this->assertStringContainsString( 'id="oobj-ac-email" name="email" type="email" autocomplete="email" required', $cart );
		$this->assertStringContainsString( 'data-url="' . rest_url( 'oobj-ac/v1/saved-carts' ) . '"', $cart );
		$this->assertStringContainsString( 'data-nonce="' . wp_create_nonce( 'wp_rest' ) . '"', $cart, 'Keeps a signed-in shopper signed in' );
		$this->assertStringContainsString( "'X-WP-Nonce': form.dataset.nonce", (string) file_get_contents( dirname( OOBJ_ABANDONED_CARTS_FILE ) . '/assets/save-cart.js' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A plugin file.
		$this->assertStringContainsString( 'name="website" type="text" tabindex="-1" autocomplete="off" aria-hidden="true"', $cart );
		$this->assertStringContainsString( '<div class="oobj-ac-save__bot-check" data-oobj-ac-bot-check="turnstile" data-sitekey="site-key" ', $cart );
		$this->assertStringContainsString( 'role="status" aria-live="polite"', $cart );

		$checkout = $module->checkout_form( '<div>checkout</div>' );
		$this->assertMatchesRegularExpression( '#<details class="oobj-ac-save oobj-ac-save--collapsed" id="save-cart">\s*<summary>Not ready to buy\? Email yourself this cart</summary>#', $checkout );
		$this->assertStringEndsWith( '</details>', trim( $checkout ) );

		delete_option( 'cfturnstile_secret' );
		$this->assertStringNotContainsString( 'data-oobj-ac-bot-check', $module->form( '' ), 'No check without both keys' );
	}

	public function test_script_and_styles_on_the_cart_and_checkout_only() {
		$module = $this->module();
		wp_dequeue_script( 'oobj-ac-save' );
		wp_dequeue_script( 'oobj-ac-bot-check' );
		wp_dequeue_style( 'oobj-ac-save' );
		$this->go_to( home_url( '/' ) );
		$module->enqueue();
		$this->assertFalse( wp_script_is( 'oobj-ac-save', 'enqueued' ) );

		$this->go_to( get_permalink( wc_get_page_id( 'checkout' ) ) );
		$module->enqueue();
		$this->assertTrue( wp_script_is( 'oobj-ac-save', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'oobj-ac-save', 'enqueued' ) );
		$this->assertSame( 'defer', wp_scripts()->get_data( 'oobj-ac-save', 'strategy' ) );
		$this->assertFalse( wp_script_is( 'oobj-ac-bot-check', 'enqueued' ), 'No bot check set up' );

		update_option( 'cfturnstile_key', 'site-key' );
		update_option( 'cfturnstile_secret', 'secret' );
		$module->enqueue();
		$this->assertTrue( wp_script_is( 'oobj-ac-bot-check', 'enqueued' ) );
	}

	public function test_admin_list_has_an_emailed_column() {
		$id = $this->insert_cart( array(), 1 );
		$this->store()->insert_saved(
			array(
				'cart_id' => $id,
				'token'   => 'abc',
				'email'   => 'sam@example.org',
				'items'   => array(),
				'days'    => 30,
			)
		);
		$table = new OOBJ_AC_List_Table( $this->store(), 7 );
		$table->prepare_items();
		$this->assertArrayHasKey( 'emailed', $table->get_columns() );
		$this->assertStringStartsWith( 'Yes, ', $table->column_emailed( $table->items[0] ) );
		$this->assertStringNotContainsString( 'Came back', $table->column_emailed( $table->items[0] ) );
		$this->assertStringContainsString( 'screen-reader-text">No<', $table->column_emailed( array( 'id' => 999999 ) ) );

		$this->store()->mark_restored( (int) $this->saved_rows()[0]['id'] );
		$table->prepare_items();
		$this->assertStringContainsString( 'Came back ', $table->column_emailed( $table->items[0] ) );
		$this->assertSame( array(), $this->store()->saved_for( array() ) );
	}

	public function test_expired_emailed_carts_are_pruned() {
		$this->saved( array(), 'old' );
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET expires_at = %s', $this->store()->saved_table(), gmdate( 'Y-m-d H:i:s', time() - 60 ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->saved( array(), 'new' );

		$this->assertNull( $this->store()->find_saved( 'old' ), 'Expired: not opened' );
		$this->assertSame( 1, oobj_abandoned_carts( OOBJ_AC_Cleanup::class )->prune() );
		$this->assertCount( 1, $this->saved_rows() );
	}
}
