/**
 * Cloudflare Turnstile as the bot check for "Email me my cart" (inc/adapters/class-oobj-ac-turnstile.php):
 * window.oobjAcBotCheck, as assets/save-cart.js expects. The widget takes the settings from Simple Cloudflare
 * Turnstile that the element carries (appearance, theme, size, language, refresh timeout): with "Interaction Only"
 * it stays hidden unless Cloudflare needs the shopper to do something. Turnstile's script loads only when start()
 * is first called.
 */
window.oobjAcBotCheck = ( function () {
	const SCRIPT = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
	// How long token() waits for an answer that isn't there yet (an invisible check still working).
	const WAIT = 5000;
	const STEP = 200;
	let widget = null;

	function response() {
		return widget && window.turnstile ? window.turnstile.getResponse( widget ) || '' : '';
	}

	return {
		start( element ) {
			if ( null !== widget || ! element ) {
				return;
			}
			widget = false;
			const data = element.dataset;
			const render = function () {
				widget = window.turnstile.render( element, {
					sitekey: data.sitekey,
					appearance: data.appearance || 'always',
					theme: data.theme || 'auto',
					size: data.size || 'normal',
					language: data.language || 'auto',
					'refresh-timeout': data.refreshTimeout || 'auto',
					'refresh-expired': 'auto',
					retry: 'auto',
				} );
			};
			if ( window.turnstile ) {
				render();
				return;
			}
			const script = document.createElement( 'script' );
			script.src = SCRIPT;
			script.async = true;
			script.addEventListener( 'load', render );
			document.head.appendChild( script );
		},

		// The answer, waiting up to WAIT for one that's still coming; '' if none comes.
		token() {
			return new Promise( function ( resolve ) {
				let waited = 0;
				( function check() {
					const answer = response();
					if ( answer || waited >= WAIT ) {
						resolve( answer );
						return;
					}
					waited += STEP;
					setTimeout( check, STEP );
				}() );
			} );
		},

		reset() {
			if ( widget && window.turnstile ) {
				window.turnstile.reset( widget );
			}
		},
	};
}() );
