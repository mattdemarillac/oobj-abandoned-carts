/**
 * "Email me my cart" (OOBJ_AC_Saved_Carts): sends the form to the REST route and says how it went. Turnstile's
 * script loads only once the shopper starts on the form, so the cart page doesn't pay for it otherwise.
 */
( function () {
	const TURNSTILE = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

	document.querySelectorAll( '.oobj-ac-save__form' ).forEach( function ( form ) {
		const email = form.querySelector( '[name="email"]' );
		const trap = form.querySelector( '[name="website"]' );
		const button = form.querySelector( 'button' );
		const status = form.querySelector( '.oobj-ac-save__status' );
		const box = form.querySelector( '.oobj-ac-save__turnstile' );
		let widget = null;

		function say( text, error ) {
			status.textContent = text;
			status.classList.toggle( 'is-error', !! error );
		}

		// Turnstile, when the store has a site key: its script, then the widget, once.
		function turnstile() {
			if ( ! box || null !== widget ) {
				return;
			}
			widget = false;
			const render = function () {
				widget = window.turnstile.render( box, { sitekey: box.dataset.sitekey } );
			};
			if ( window.turnstile ) {
				render();
				return;
			}
			const script = document.createElement( 'script' );
			script.src = TURNSTILE;
			script.async = true;
			script.addEventListener( 'load', render );
			document.head.appendChild( script );
		}

		email.addEventListener( 'focus', turnstile );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			if ( ! email.value || ! email.checkValidity() ) {
				say( 'Enter a valid email address.', true );
				email.focus();
				return;
			}
			button.disabled = true;
			say( 'Sending…', false );
			window
				.fetch( form.dataset.url, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( {
						email: email.value,
						website: trap ? trap.value : '',
						turnstile: widget && window.turnstile ? window.turnstile.getResponse( widget ) || '' : '',
					} ),
				} )
				.then( function ( response ) {
					return response.json().then( function ( data ) {
						if ( ! response.ok ) {
							throw new Error( ( data && data.message ) || 'Something went wrong. Please try again.' );
						}
					} );
				} )
				.then( function () {
					say( 'Sent. Check your inbox for your cart.', false );
					form.reset();
					if ( 'function' === typeof window.gtag ) {
						window.gtag( 'event', 'save_cart' );
					}
				} )
				.catch( function ( error ) {
					say( error.message, true );
				} )
				.finally( function () {
					button.disabled = false;
					if ( widget && window.turnstile ) {
						window.turnstile.reset( widget );
					}
				} );
		} );
	} );
}() );
