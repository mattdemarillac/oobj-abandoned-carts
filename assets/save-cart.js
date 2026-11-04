/**
 * "Email me my cart" (OOBJ_AC_Saved_Carts): sends the form to the REST route and says how it went.
 *
 * A bot check, when the site has one (OOBJ_AC_Bot_Check), is window.oobjAcBotCheck: started when the shopper
 * starts on the form, its token() sent as bot_check, reset() after each try. Without one, nothing is sent for it.
 *
 * When the cart is sent, the form fires the DOM event "oobj-ac:saved" (bubbling, from the form) for analytics or
 * anything else, and sends the GA4 event save_cart when the page has gtag().
 */
( function () {
	function botCheck() {
		return window.oobjAcBotCheck || null;
	}

	document.querySelectorAll( '.oobj-ac-save__form' ).forEach( function ( form ) {
		const email = form.querySelector( '[name="email"]' );
		const trap = form.querySelector( '[name="website"]' );
		const button = form.querySelector( 'button' );
		const status = form.querySelector( '.oobj-ac-save__status' );
		const checkElement = form.querySelector( '[data-oobj-ac-bot-check]' );

		function say( text, error ) {
			status.textContent = text;
			status.classList.toggle( 'is-error', !! error );
		}

		email.addEventListener( 'focus', function () {
			if ( checkElement && botCheck() ) {
				botCheck().start( checkElement );
			}
		} );

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
						bot_check: botCheck() ? botCheck().token() : '',
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
					form.dispatchEvent( new CustomEvent( 'oobj-ac:saved', { bubbles: true } ) );
					if ( 'function' === typeof window.gtag ) {
						window.gtag( 'event', 'save_cart' );
					}
				} )
				.catch( function ( error ) {
					say( error.message, true );
				} )
				.finally( function () {
					button.disabled = false;
					if ( botCheck() ) {
						botCheck().reset();
					}
				} );
		} );
	} );
}() );
