<?php
/**
 * The WooCommerce → Saved carts page.
 *
 * @package oobj-abandoned-carts
 */

declare( strict_types=1 );

class AdminPageTest extends OOBJ_AC_TestCase {

	public function tear_down() {
		unset( $_GET['days'] );
		parent::tear_down();
	}

	private function page(): OOBJ_AC_Admin_Page {
		return oobj_abandoned_carts( OOBJ_AC_Admin_Page::class );
	}

	private function render(): string {
		set_current_screen( 'woocommerce_page_' . OOBJ_AC_Admin_Page::SLUG );
		ob_start();
		$this->page()->render();
		return (string) ob_get_clean();
	}

	public function test_lists_the_weeks_unfinished_carts() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		$product = $this->create_product( 'Titanium Hoop' );
		$this->insert_cart(
			array(
				'email' => 'sam@example.org',
				'name'  => 'Sam Shopper',
				'items' => array(
					array(
						'product_id'   => $product->get_id(),
						'variation_id' => 0,
						'name'         => 'Titanium Hoop',
						'quantity'     => 3,
						'line_total'   => 30.0,
					),
				),
				'total' => 30,
			),
			2
		);
		$this->insert_cart( array( 'email' => 'old@example.org' ), 9 );
		$failed = $this->create_order( 'failed' );
		$this->insert_cart( array( 'order_id' => $failed->get_id() ), 1 );

		$html = $this->render();

		$this->assertStringContainsString( 'Sam Shopper', $html );
		$this->assertStringContainsString( 'mailto:sam@example.org', $html );
		$this->assertStringContainsString( '3 × <a', $html );
		$this->assertStringContainsString( 'Titanium Hoop', $html );
		$this->assertStringContainsString( 'Payment failed', $html );
		$this->assertStringContainsString( 'Order #' . $failed->get_order_number(), $html );
		$this->assertStringNotContainsString( 'old@example.org', $html );
		$this->assertMatchesRegularExpression( '/<strong>2<\/strong> unfinished carts worth/', $html );
	}

	public function test_the_period_comes_from_the_query_but_only_from_the_offered_periods() {
		$this->assertSame( 7, $this->page()->days() );
		$_GET['days'] = '14';
		$this->assertSame( 14, $this->page()->days() );
		$_GET['days'] = '365';
		$this->assertSame( 7, $this->page()->days() );
	}

	public function test_customers_cannot_see_the_page() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		$this->expectException( WPDieException::class );
		$this->page()->render();
	}

	public function test_the_menu_item_is_for_shop_managers() {
		global $submenu;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->page()->add_menu();
		$this->assertContains( OOBJ_AC_Admin_Page::SLUG, array_column( $submenu['woocommerce'] ?? array(), 2 ) );
	}
}
