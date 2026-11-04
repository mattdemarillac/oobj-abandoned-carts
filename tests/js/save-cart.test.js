/**
 * assets/save-cart.js: the "Email me my cart" form.
 */
const { run } = require( './helpers' );

const FORM = ( check = '' ) => `<form class="oobj-ac-save__form" data-url="/wp-json/oobj-ac/v1/saved-carts" data-nonce="nonce123" novalidate>
	<input name="email" type="email" required />
	<button type="submit">Email me my cart</button>
	<input name="website" type="text" />
	${ check }
	<p class="oobj-ac-save__status"></p>
</form>`;

function answer( ok, body ) {
	return Promise.resolve( { ok, json: () => Promise.resolve( body ) } );
}

function setUp( { check = '', fetch = answer( true, { sent: true } ), botCheck } = {} ) {
	document.body.innerHTML = FORM( check );
	window.fetch = jest.fn( () => fetch );
	if ( botCheck ) {
		window.oobjAcBotCheck = botCheck;
	}
	run( 'save-cart' );
	return {
		form: document.querySelector( 'form' ),
		email: document.querySelector( '[name="email"]' ),
		trap: document.querySelector( '[name="website"]' ),
		button: document.querySelector( 'button' ),
		status: document.querySelector( '.oobj-ac-save__status' ),
	};
}

function submit( form ) {
	form.dispatchEvent( new Event( 'submit', { cancelable: true } ) );
	return new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
}

function body() {
	return JSON.parse( window.fetch.mock.calls[ 0 ][ 1 ].body );
}

describe( 'save-cart', () => {
	afterEach( () => {
		delete window.oobjAcBotCheck;
		delete window.fetch;
		window.location.hash = '';
	} );

	test( 'sends the email with the nonce and says it was sent', async () => {
		const { form, email, button, status } = setUp();
		const saved = jest.fn();
		document.addEventListener( 'oobj-ac:saved', saved );
		email.value = 'sam@example.org';

		await submit( form );

		expect( window.fetch ).toHaveBeenCalledWith( '/wp-json/oobj-ac/v1/saved-carts', expect.objectContaining( { method: 'POST', credentials: 'same-origin' } ) );
		expect( window.fetch.mock.calls[ 0 ][ 1 ].headers ).toEqual( { 'Content-Type': 'application/json', 'X-WP-Nonce': 'nonce123' } );
		expect( body() ).toEqual( { email: 'sam@example.org', website: '', bot_check: '' } );
		expect( status.textContent ).toBe( 'Sent. Check your inbox for your cart.' );
		expect( status.classList.contains( 'is-error' ) ).toBe( false );
		expect( email.value ).toBe( '' );
		expect( button.disabled ).toBe( false );
		expect( saved ).toHaveBeenCalledTimes( 1 );
		document.removeEventListener( 'oobj-ac:saved', saved );
	} );

	test( 'an invalid address is refused without sending', async () => {
		const { form, email, status } = setUp();
		email.value = 'not an email';

		await submit( form );

		expect( window.fetch ).not.toHaveBeenCalled();
		expect( status.textContent ).toBe( 'Enter a valid email address.' );
		expect( status.classList.contains( 'is-error' ) ).toBe( true );
		expect( document.activeElement ).toBe( email );
	} );

	test( 'shows the server\'s message, or a general one', async () => {
		let page = setUp( { fetch: answer( false, { message: 'Your cart is empty.' } ) } );
		page.email.value = 'sam@example.org';
		await submit( page.form );
		expect( page.status.textContent ).toBe( 'Your cart is empty.' );
		expect( page.status.classList.contains( 'is-error' ) ).toBe( true );

		page = setUp( { fetch: answer( false, null ) } );
		page.email.value = 'sam@example.org';
		await submit( page.form );
		expect( page.status.textContent ).toBe( 'Something went wrong. Please try again.' );
	} );

	test( 'a network failure is shown', async () => {
		const { form, email, status, button } = setUp( { fetch: Promise.reject( new Error( 'Failed to fetch' ) ) } );
		email.value = 'sam@example.org';

		await submit( form );

		expect( status.textContent ).toBe( 'Failed to fetch' );
		expect( button.disabled ).toBe( false );
	} );

	test( 'sends the trap field as filled in', async () => {
		const { form, email, trap } = setUp();
		email.value = 'sam@example.org';
		trap.value = 'http://spam.example';

		await submit( form );

		expect( body().website ).toBe( 'http://spam.example' );
	} );

	test( 'a bot check starts on focus, its answer (or promise of one) is sent, and it is reset', async () => {
		const botCheck = { start: jest.fn(), token: jest.fn( () => Promise.resolve( 'answer' ) ), reset: jest.fn() };
		const { form, email } = setUp( { check: '<div data-oobj-ac-bot-check="test"></div>', botCheck } );
		const element = document.querySelector( '[data-oobj-ac-bot-check]' );

		email.dispatchEvent( new Event( 'focus' ) );
		expect( botCheck.start ).toHaveBeenCalledWith( element );

		email.value = 'sam@example.org';
		await submit( form );

		expect( botCheck.start ).toHaveBeenCalledTimes( 2 ); // Again on submit, in case of autofill.
		expect( body().bot_check ).toBe( 'answer' );
		expect( botCheck.reset ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'a check without its element in the form, or no check script, is left alone', async () => {
		const botCheck = { start: jest.fn(), token: jest.fn(), reset: jest.fn() };
		let page = setUp( { botCheck } );
		page.email.dispatchEvent( new Event( 'focus' ) );
		page.email.value = 'sam@example.org';
		await submit( page.form );
		expect( botCheck.start ).not.toHaveBeenCalled();
		expect( body().bot_check ).toBe( '' );

		delete window.oobjAcBotCheck;
		page = setUp( { check: '<div data-oobj-ac-bot-check="test"></div>' } );
		page.email.dispatchEvent( new Event( 'focus' ) );
		page.email.value = 'sam@example.org';
		window.fetch.mockClear();
		await submit( page.form );
		expect( body().bot_check ).toBe( '' );
	} );

	test( 'from the mini cart\'s link, the field gets the cursor once the page has loaded', () => {
		jest.useFakeTimers();
		window.location.hash = '#save-cart';
		const { email } = setUp();
		jest.runAllTimers();

		expect( document.activeElement ).toBe( email );
		jest.useRealTimers();
	} );

	test( 'from the link while the page is still loading: on load', () => {
		jest.useFakeTimers();
		const readyState = jest.spyOn( document, 'readyState', 'get' ).mockReturnValue( 'loading' );
		window.location.hash = '#save-cart';
		const { email } = setUp();
		jest.runAllTimers();
		expect( document.activeElement ).not.toBe( email );

		window.dispatchEvent( new Event( 'load' ) );
		jest.runAllTimers();

		expect( document.activeElement ).toBe( email );
		readyState.mockRestore();
		jest.useRealTimers();
	} );

	test( 'a form without the trap field sends it empty', async () => {
		document.body.innerHTML = FORM().replace( '<input name="website" type="text" />', '' );
		window.fetch = jest.fn( () => answer( true, { sent: true } ) );
		run( 'save-cart' );
		document.querySelector( '[name="email"]' ).value = 'sam@example.org';

		await submit( document.querySelector( 'form' ) );

		expect( body().website ).toBe( '' );
	} );

	test( 'a form without a nonce sends an empty one', async () => {
		const { form, email } = setUp();
		delete form.dataset.nonce;
		email.value = 'sam@example.org';

		await submit( form );

		expect( window.fetch.mock.calls[ 0 ][ 1 ].headers[ 'X-WP-Nonce' ] ).toBe( '' );
	} );
} );
