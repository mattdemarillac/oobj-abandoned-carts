/**
 * assets/mini-cart.js: "Email me this cart" in the Mini-Cart drawer.
 */
const { run } = require( './helpers' );

const DATA = '<span class="oobj-ac-mini-cart-link" hidden data-url="https://shop.example/cart/#save-cart" data-text="Email me this cart"></span>';

function links() {
	return document.querySelectorAll( '.oobj-ac-mini-cart' );
}

describe( 'mini-cart', () => {
	afterEach( () => {
		document.body.innerHTML = '';
	} );

	test( 'adds the link to a footer that is there, once', () => {
		document.body.innerHTML = '<div class="wc-block-mini-cart"><div class="wc-block-mini-cart__footer"></div></div>' + DATA;

		run( 'mini-cart' );

		expect( links() ).toHaveLength( 1 );
		expect( links()[ 0 ].getAttribute( 'href' ) ).toBe( 'https://shop.example/cart/#save-cart' );
		expect( links()[ 0 ].textContent ).toBe( 'Email me this cart' );
	} );

	test( 'adds it when the drawer opens and draws its footer', async () => {
		document.body.innerHTML = '<div class="wc-block-mini-cart"></div>' + DATA;
		run( 'mini-cart' );
		expect( links() ).toHaveLength( 0 );

		document.querySelector( '.wc-block-mini-cart' ).insertAdjacentHTML( 'beforeend', '<div class="wc-block-mini-cart__footer"></div>' );
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

		expect( links() ).toHaveLength( 1 );
	} );

	test( 'nothing without its data or a mini cart', () => {
		document.body.innerHTML = '<div class="wc-block-mini-cart"><div class="wc-block-mini-cart__footer"></div></div>';
		run( 'mini-cart' );
		expect( links() ).toHaveLength( 0 );

		document.body.innerHTML = '<div class="wc-block-mini-cart__footer"></div>' + DATA;
		run( 'mini-cart' );
		expect( links() ).toHaveLength( 0 );
	} );
} );
