/**
 * assets/adapters/ga4.js: save_cart for GA4, only when the page has gtag().
 */
const { run } = require( './helpers' );

describe( 'ga4 adapter', () => {
	beforeAll( () => run( 'adapters/ga4' ) );

	afterEach( () => {
		delete window.gtag;
	} );

	test( 'sends save_cart when a cart is sent', () => {
		window.gtag = jest.fn();

		document.dispatchEvent( new CustomEvent( 'oobj-ac:saved' ) );

		expect( window.gtag ).toHaveBeenCalledWith( 'event', 'save_cart' );
	} );

	test( 'does nothing without gtag', () => {
		expect( () => document.dispatchEvent( new CustomEvent( 'oobj-ac:saved' ) ) ).not.toThrow();
		expect( window.gtag ).toBeUndefined();
	} );
} );
