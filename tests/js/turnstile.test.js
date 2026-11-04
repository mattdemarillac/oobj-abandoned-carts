/**
 * assets/adapters/turnstile.js: Cloudflare Turnstile as window.oobjAcBotCheck.
 */
const { run } = require( './helpers' );

function element( data = {} ) {
	const div = document.createElement( 'div' );
	Object.assign( div.dataset, { sitekey: 'site-key' }, data );
	document.body.appendChild( div );
	return div;
}

function turnstile( response = 'answer' ) {
	return { render: jest.fn( () => 'widget-1' ), getResponse: jest.fn( () => response ), reset: jest.fn() };
}

describe( 'turnstile adapter', () => {
	beforeEach( () => {
		document.head.innerHTML = '';
		document.body.innerHTML = '';
		delete window.turnstile;
		run( 'adapters/turnstile' );
	} );

	afterEach( () => {
		jest.useRealTimers();
		delete window.oobjAcBotCheck;
	} );

	test( 'loads Turnstile\'s script once, then renders with the plugin\'s settings', () => {
		const box = element( { appearance: 'interaction-only', theme: 'light', size: 'compact', language: 'en', refreshTimeout: 'manual' } );

		window.oobjAcBotCheck.start( box );
		window.oobjAcBotCheck.start( box );

		const scripts = document.head.querySelectorAll( 'script' );
		expect( scripts ).toHaveLength( 1 );
		expect( scripts[ 0 ].src ).toBe( 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit' );

		window.turnstile = turnstile();
		scripts[ 0 ].dispatchEvent( new Event( 'load' ) );
		expect( window.turnstile.render ).toHaveBeenCalledWith( box, {
			sitekey: 'site-key',
			appearance: 'interaction-only',
			theme: 'light',
			size: 'compact',
			language: 'en',
			'refresh-timeout': 'manual',
			'refresh-expired': 'auto',
			retry: 'auto',
		} );
	} );

	test( 'Turnstile\'s defaults, and rendering at once when its script is already there', () => {
		window.turnstile = turnstile();

		window.oobjAcBotCheck.start( element() );

		expect( window.turnstile.render ).toHaveBeenCalledWith( expect.anything(), expect.objectContaining( { appearance: 'always', theme: 'auto', size: 'normal', language: 'auto', 'refresh-timeout': 'auto' } ) );
		expect( document.head.querySelector( 'script' ) ).toBeNull();
	} );

	test( 'nothing without an element', () => {
		window.oobjAcBotCheck.start( null );

		expect( document.head.querySelector( 'script' ) ).toBeNull();
	} );

	test( 'token() gives the answer at once when there is one', async () => {
		window.turnstile = turnstile( 'answer' );
		window.oobjAcBotCheck.start( element() );

		await expect( window.oobjAcBotCheck.token() ).resolves.toBe( 'answer' );
	} );

	test( 'token() waits for an answer still coming (an invisible check)', async () => {
		jest.useFakeTimers();
		window.turnstile = turnstile( '' );
		window.oobjAcBotCheck.start( element() );

		const token = window.oobjAcBotCheck.token();
		window.turnstile.getResponse.mockReturnValue( 'late answer' );
		jest.advanceTimersByTime( 200 );

		await expect( token ).resolves.toBe( 'late answer' );
	} );

	test( 'token() gives up after 5 seconds, and is empty before the check starts', async () => {
		jest.useFakeTimers();
		window.turnstile = turnstile( '' );
		window.oobjAcBotCheck.start( element() );

		const token = window.oobjAcBotCheck.token();
		jest.advanceTimersByTime( 5000 );
		await expect( token ).resolves.toBe( '' );

		delete window.turnstile;
		const before = window.oobjAcBotCheck.token();
		jest.advanceTimersByTime( 5000 );
		await expect( before ).resolves.toBe( '' );
	} );

	test( 'reset() resets the widget, and does nothing before it exists', () => {
		window.oobjAcBotCheck.reset();

		window.turnstile = turnstile();
		window.oobjAcBotCheck.start( element() );
		window.oobjAcBotCheck.reset();

		expect( window.turnstile.reset ).toHaveBeenCalledWith( 'widget-1' );
	} );
} );
