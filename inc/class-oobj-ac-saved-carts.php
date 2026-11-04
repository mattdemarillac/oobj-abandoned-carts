<?php
/**
 * Class OOBJ_AC_Saved_Carts.
 *
 * @package oobj-abandoned-carts
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * "Email me my cart": under the cart (and collapsed under the checkout, for shoppers who go straight there), a
 * shopper enters their email and gets the cart with a link that puts it
 * back, on any device, for OOBJ_AC_Cleanup::retention_days(). The link (/cart/?saved-cart=TOKEN) adds what's
 * still for sale to the cart they have open, then drops the token from the address.
 *
 * Against misuse (it sends email from the store's domain): it needs a cart in the shopper's session, a
 * hidden field bots fill in, Cloudflare Turnstile when Simple Cloudflare Turnstile has keys, and at most
 * MAX_PER_EMAIL emails to an address a day and MAX_PER_IP per IP an hour. The address is used only to send
 * the cart; the cart's record (WooCommerce → Saved carts) gets it if it had none.
 */
class OOBJ_AC_Saved_Carts implements OOBJ_AC_Module {

	const ROUTE_NAMESPACE = 'oobj-ac/v1';
	const ROUTE           = '/saved-carts';
	const QUERY_VAR       = 'saved-cart';
	const MAX_PER_EMAIL   = 3;
	const MAX_PER_IP      = 5;

	/**
	 * Session key: emailed carts already put back in this session, so a second click doesn't add them twice.
	 */
	const RESTORED_KEY = 'oobj_ac_restored';

	/**
	 * The carts' records.
	 *
	 * @var OOBJ_AC_Store
	 */
	private $store;

	/**
	 * @param OOBJ_AC_Store $store The carts' records.
	 */
	public function __construct( OOBJ_AC_Store $store ) {
		$this->store = $store;
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
		add_action( 'template_redirect', array( $this, 'restore' ) );
		add_filter( 'render_block_woocommerce/cart', array( $this, 'form' ) );
		add_filter( 'render_block_woocommerce/checkout', array( $this, 'checkout_form' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function register_route(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'email'     => array(
						'type'     => 'string',
						'required' => true,
					),
					'website'   => array( 'type' => 'string' ),
					'turnstile' => array( 'type' => 'string' ),
				),
			)
		);
	}

	/**
	 * Emails the shopper their cart.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save( WP_REST_Request $request ) {
		// A bot filled in the hidden field: say it worked, send nothing.
		if ( '' !== trim( (string) $request->get_param( 'website' ) ) ) {
			return new WP_REST_Response( array( 'sent' => true ) );
		}

		$email = sanitize_email( (string) $request->get_param( 'email' ) );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'oobj_ac_email', __( 'Enter a valid email address.', 'oobj-abandoned-carts' ), array( 'status' => 400 ) );
		}

		$ip = (string) WC_Geolocation::get_ip_address();
		if ( ! $this->turnstile_passed( (string) $request->get_param( 'turnstile' ), $ip ) ) {
			return new WP_Error( 'oobj_ac_turnstile', __( 'Please confirm you\'re not a robot, then try again.', 'oobj-abandoned-carts' ), array( 'status' => 400 ) );
		}

		$ip_key = 'oobj_ac_saves_' . md5( $ip );
		$by_ip  = (int) get_transient( $ip_key );
		if ( $by_ip >= self::MAX_PER_IP || $this->store->saved_today( $email ) >= self::MAX_PER_EMAIL ) {
			return new WP_Error( 'oobj_ac_limit', __( 'That\'s a few emails already. Please try again later.', 'oobj-abandoned-carts' ), array( 'status' => 429 ) );
		}

		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
		$cart = WC()->cart;
		if ( ! $cart || $cart->is_empty() ) {
			return new WP_Error( 'oobj_ac_empty', __( 'Your cart is empty.', 'oobj-abandoned-carts' ), array( 'status' => 400 ) );
		}

		$items = $this->items( $cart );
		$token = bin2hex( random_bytes( 16 ) );
		$open  = WC()->session ? $this->store->find_open( (string) WC()->session->get_customer_id() ) : null;
		$this->store->insert_saved(
			array(
				'cart_id' => $open ? (int) $open['id'] : 0,
				'token'   => $token,
				'email'   => $email,
				'items'   => $items,
				'days'    => OOBJ_AC_Cleanup::retention_days(),
			)
		);
		if ( $open && '' === (string) $open['email'] ) {
			$this->store->update( (int) $open['id'], array( 'email' => $email ) );
		}
		set_transient( $ip_key, $by_ip + 1, HOUR_IN_SECONDS );

		$this->send( $email, $items, $token );
		return new WP_REST_Response( array( 'sent' => true ) );
	}

	/**
	 * Puts an emailed cart back: on the cart page with ?saved-cart=TOKEN.
	 *
	 * @internal Action callback.
	 */
	public function restore(): void {
		$token = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The token is the authorisation.
		if ( '' === $token || ! $this->on_page( 'cart' ) || ! WC()->cart ) {
			return;
		}

		$saved = $this->store->find_saved( $token );
		if ( ! $saved ) {
			wc_add_notice( __( 'That saved cart link has expired.', 'oobj-abandoned-carts' ), 'notice' );
		} else {
			$this->put_back( $saved );
		}
		$this->redirect();
	}

	/**
	 * Adds an emailed cart's items to the cart, once per session, and says what couldn't be added.
	 *
	 * @param array $saved The emailed cart (OOBJ_AC_Store::find_saved()).
	 */
	private function put_back( array $saved ): void {
		$restored = (array) WC()->session->get( self::RESTORED_KEY );
		if ( ! in_array( $saved['id'], $restored, true ) ) {
			$skipped = array();
			foreach ( $saved['items'] as $item ) {
				if ( ! $this->add( $item ) ) {
					$skipped[] = (string) ( $item['name'] ?? '' );
				}
			}
			$this->store->mark_restored( $saved['id'] );
			WC()->session->set( self::RESTORED_KEY, array_merge( $restored, array( $saved['id'] ) ) );
			wc_add_notice( __( 'Your saved cart is back.', 'oobj-abandoned-carts' ) );
			if ( $skipped ) {
				/* translators: %s: product names. */
				wc_add_notice( sprintf( __( 'No longer available: %s.', 'oobj-abandoned-carts' ), implode( ', ', array_filter( $skipped ) ) ), 'notice' );
			}
		}
	}

	/**
	 * The form, after the cart block, when the cart has something in it.
	 *
	 * @internal Filter callback.
	 * @param string $html The cart block.
	 */
	public function form( $html ): string {
		return (string) $html . $this->markup( false );
	}

	/**
	 * The form, collapsed, after the checkout block: not in the way of paying.
	 *
	 * @internal Filter callback.
	 * @param string $html The checkout block.
	 */
	public function checkout_form( $html ): string {
		return (string) $html . $this->markup( true );
	}

	/**
	 * The form, when the cart has something in it.
	 *
	 * @param bool $collapsed Behind a "Not ready to buy?" summary (the checkout).
	 */
	private function markup( bool $collapsed ): string {
		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			return '';
		}
		$site_key = (string) get_option( 'cfturnstile_key', '' );
		ob_start();
		?>
		<?php if ( $collapsed ) : ?>
		<details class="oobj-ac-save oobj-ac-save--collapsed" id="save-cart">
			<summary><?php esc_html_e( 'Not ready to buy? Email yourself this cart', 'oobj-abandoned-carts' ); ?></summary>
		<?php else : ?>
		<section class="oobj-ac-save" id="save-cart" aria-labelledby="oobj-ac-save-title">
			<h2 class="oobj-ac-save__title" id="oobj-ac-save-title"><?php esc_html_e( 'Not ready to check out?', 'oobj-abandoned-carts' ); ?></h2>
		<?php endif; ?>
			<p class="oobj-ac-save__text"><?php esc_html_e( 'Email yourself this cart and pick it up on any device. We\'ll only use your email to send it.', 'oobj-abandoned-carts' ); ?></p>
			<form class="oobj-ac-save__form" data-url="<?php echo esc_url( rest_url( self::ROUTE_NAMESPACE . self::ROUTE ) ); ?>" novalidate>
				<label class="oobj-ac-save__label" for="oobj-ac-email"><?php esc_html_e( 'Email', 'oobj-abandoned-carts' ); ?></label>
				<div class="oobj-ac-save__row">
					<input class="oobj-ac-save__email" id="oobj-ac-email" name="email" type="email" autocomplete="email" required />
					<button class="oobj-ac-save__button wp-element-button" type="submit"><?php esc_html_e( 'Email me my cart', 'oobj-abandoned-carts' ); ?></button>
				</div>
				<?php /* Bots fill it in; people don't see it. */ ?>
				<input class="oobj-ac-save__trap" name="website" type="text" tabindex="-1" autocomplete="off" aria-hidden="true" />
				<?php if ( '' !== $site_key ) : ?>
					<div class="oobj-ac-save__turnstile" data-sitekey="<?php echo esc_attr( $site_key ); ?>"></div>
				<?php endif; ?>
				<p class="oobj-ac-save__status" role="status" aria-live="polite"></p>
			</form>
		<?php echo $collapsed ? '</details>' : '</section>'; ?>
		<?php
		return (string) ob_get_clean();
	}

	public function enqueue(): void {
		if ( ! $this->on_page( 'cart' ) && ! $this->on_page( 'checkout' ) ) {
			return;
		}
		$file = dirname( __DIR__ ) . '/oobj-abandoned-carts.php';
		wp_enqueue_style( 'oobj-ac-save', plugins_url( 'assets/save-cart.css', $file ), array(), (string) filemtime( dirname( __DIR__ ) . '/assets/save-cart.css' ) );
		wp_enqueue_script(
			'oobj-ac-save',
			plugins_url( 'assets/save-cart.js', $file ),
			array(),
			(string) filemtime( dirname( __DIR__ ) . '/assets/save-cart.js' ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * The cart's items, as the link puts them back.
	 *
	 * @param WC_Cart $cart The cart.
	 * @return array<int, array{product_id: int, variation_id: int, variation: array, quantity: int, name: string}>
	 */
	private function items( WC_Cart $cart ): array {
		$items = array();
		foreach ( $cart->get_cart() as $item ) {
			$product = $item['data'] ?? null;
			$items[] = array(
				'product_id'   => (int) $item['product_id'],
				'variation_id' => (int) ( $item['variation_id'] ?? 0 ),
				'variation'    => (array) ( $item['variation'] ?? array() ),
				'quantity'     => (int) $item['quantity'],
				'name'         => $product instanceof WC_Product ? wp_strip_all_tags( $product->get_name() ) : '',
			);
		}
		return $items;
	}

	/**
	 * Adds a saved item to the cart, if it's still for sale.
	 *
	 * @param array $item The saved item.
	 * @return bool Whether it was added.
	 */
	private function add( array $item ): bool {
		$product = wc_get_product( (int) ( ( $item['variation_id'] ?? 0 ) ? $item['variation_id'] : ( $item['product_id'] ?? 0 ) ) );
		if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			return false;
		}
		return false !== WC()->cart->add_to_cart( (int) $item['product_id'], max( 1, (int) ( $item['quantity'] ?? 1 ) ), (int) ( $item['variation_id'] ?? 0 ), (array) ( $item['variation'] ?? array() ) );
	}

	/**
	 * The email: the cart's items and the link back.
	 *
	 * @param string $email Where to.
	 * @param array  $items The items.
	 * @param string $token The link's token.
	 */
	private function send( string $email, array $items, string $token ): void {
		$link = add_query_arg( self::QUERY_VAR, $token, wc_get_cart_url() );
		$rows = '';
		foreach ( $items as $item ) {
			$product = wc_get_product( $item['variation_id'] ? $item['variation_id'] : $item['product_id'] );
			if ( ! $product ) {
				continue;
			}
			$image = wp_get_attachment_image_url( (int) $product->get_image_id(), 'thumbnail' );
			$rows .= sprintf(
				'<tr><td style="padding:8px 12px 8px 0;width:64px">%s</td><td style="padding:8px 0">%s<br><span style="color:#717171">%s × %s</span></td></tr>',
				$image ? sprintf( '<img src="%s" width="64" height="64" alt="" style="border-radius:4px">', esc_url( $image ) ) : '',
				esc_html( wp_strip_all_tags( $product->get_name() ) ),
				esc_html( (string) $item['quantity'] ),
				wp_strip_all_tags( wc_price( (float) $product->get_price() ) )
			);
		}
		$days = OOBJ_AC_Cleanup::retention_days();
		$body = sprintf(
			'<p>%s</p><table cellspacing="0" cellpadding="0" style="width:100%%;margin:16px 0">%s</table><p style="margin:24px 0"><a href="%s" style="display:inline-block;padding:12px 24px;background:#222;color:#fff;text-decoration:none;border-radius:4px;font-weight:600">%s</a></p><p style="color:#717171">%s</p>',
			esc_html__( 'Here\'s the cart you saved. The button puts it back, on any device.', 'oobj-abandoned-carts' ),
			$rows,
			esc_url( $link ),
			esc_html__( 'Back to my cart', 'oobj-abandoned-carts' ),
			/* translators: %d: days. */
			esc_html( sprintf( _n( 'The link works for %d day. Prices and stock are as they are when you come back.', 'The link works for %d days. Prices and stock are as they are when you come back.', $days, 'oobj-abandoned-carts' ), $days ) )
		);
		$mailer = WC()->mailer();
		$mailer->send(
			$email,
			/* translators: %s: store name. */
			sprintf( __( 'Your saved cart at %s', 'oobj-abandoned-carts' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			$mailer->wrap_message( __( 'Your saved cart', 'oobj-abandoned-carts' ), $body )
		);
	}

	/**
	 * Whether Turnstile passed, or isn't set up (no secret key in Simple Cloudflare Turnstile).
	 *
	 * @param string $response The widget's response.
	 * @param string $ip       The shopper's IP.
	 */
	private function turnstile_passed( string $response, string $ip ): bool {
		$secret = (string) get_option( 'cfturnstile_secret', '' );
		if ( '' === $secret ) {
			return true;
		}
		if ( '' === $response ) {
			return false;
		}
		$answer = wp_remote_post(
			'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => $secret,
					'response' => $response,
					'remoteip' => $ip,
				),
			)
		);
		$data   = json_decode( (string) wp_remote_retrieve_body( $answer ), true );
		return is_array( $data ) && ! empty( $data['success'] );
	}

	/**
	 * Whether this is the store's cart or checkout page. Not is_cart(), which is also true wherever
	 * WOOCOMMERCE_CART was defined.
	 *
	 * @param string $page 'cart' or 'checkout'.
	 */
	private function on_page( string $page ): bool {
		$id = wc_get_page_id( $page );
		return $id > 0 && is_page( $id );
	}

	/**
	 * To the cart, without the token in the address.
	 */
	private function redirect(): void {
		wp_safe_redirect( wc_get_cart_url() );
		$this->stop();
	}

	/**
	 * Ends the request after a redirect. Its own method so tests can stop it.
	 *
	 * @codeCoverageIgnore
	 */
	protected function stop(): void {
		exit;
	}
}
