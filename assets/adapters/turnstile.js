/**
 * Cloudflare Turnstile as the bot check for "Email me my cart" (inc/adapters/class-oobj-ac-turnstile.php):
 * window.oobjAcBotCheck, as
 * assets/save-cart.js expects. Turnstile's script loads only when start() is first called.
 */
window.oobjAcBotCheck = ( function () {
	const SCRIPT = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
	let widget = null;

	return {
		start( element ) {
			if ( null !== widget || ! element ) {
				return;
			}
			widget = false;
			const render = function () {
				widget = window.turnstile.render( element, { sitekey: element.dataset.sitekey } );
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
		token() {
			return widget && window.turnstile ? window.turnstile.getResponse( widget ) || '' : '';
		},
		reset() {
			if ( widget && window.turnstile ) {
				window.turnstile.reset( widget );
			}
		},
	};
}() );
