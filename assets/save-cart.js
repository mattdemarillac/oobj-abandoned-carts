/**
 * "Email me my cart" (OOBJ_AC_Saved_Carts): sends the form to the REST route and says how it went.
 *
 * A bot check, when the site has one (OOBJ_AC_Bot_Check), is window.oobjAcBotCheck: started when the shopper
 * starts on the form (or sends it), its token() (a string, or a promise of one) sent as bot_check, reset() after
 * each try. Without one, nothing is sent for it.
 *
 * When the cart is sent, the form fires the DOM event "oobj-ac:saved" (bubbling, from the form), for analytics
 * adapters (assets/adapters/ga4.js) or anything else.
 */
( function () {
	function botCheck() {
		return window.oobjAcBotCheck || null;
	}

	function send( form, email, trap, token ) {
		return window.fetch( form.dataset.url, {
			method: 'POST',
			credentials: 'same-origin',
			// Without the nonce, WordPress treats a signed-in shopper as signed out, and their cart isn't found.
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': form.dataset.nonce || '' },
			body: JSON.stringify( {
				email: email.value,
				website: trap ? trap.value : '',
				bot_check: token,
			} ),
		} );
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

		function check() {
			return checkElement ? botCheck() : null;
		}

		email.addEventListener( 'focus', function () {
			if ( check() ) {
				check().start( checkElement );
			}
		} );

		// From the mini cart's "Email me this cart" link: straight to the field, once the page has loaded (focus given
		// earlier is lost as the cart block draws).
		if ( '#save-cart' === window.location.hash ) {
			const focus = function () {
				setTimeout( function () {
					email.focus();
				}, 0 );
			};
			if ( 'complete' === document.readyState ) {
				focus();
			} else {
				window.addEventListener( 'load', focus, { once: true } );
			}
		}

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			if ( ! email.value || ! email.checkValidity() ) {
				say( 'Enter a valid email address.', true );
				email.focus();
				return;
			}
			button.disabled = true;
			say( 'Sending…', false );
			if ( check() ) {
				// In case the field was filled without focus (autofill).
				check().start( checkElement );
			}
			// The check's answer, which an invisible check may still be working on.
			Promise.resolve( check() ? check().token() : '' )
				.then( function ( token ) {
					return send( form, email, trap, token );
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
				} )
				.catch( function ( error ) {
					say( error.message, true );
				} )
				.finally( function () {
					button.disabled = false;
					if ( check() ) {
						check().reset();
					}
				} );
		} );
	} );
}() );
