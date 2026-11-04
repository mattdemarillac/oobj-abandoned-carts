/**
 * Google Analytics 4 for "Email me my cart" (inc/adapters/class-oobj-ac-ga4.php): sends the event save_cart when
 * the form fires "oobj-ac:saved". Only when the page has GA's gtag() at that moment; without it, nothing is sent
 * and nothing is queued.
 */
document.addEventListener( 'oobj-ac:saved', function () {
	if ( 'function' === typeof window.gtag ) {
		window.gtag( 'event', 'save_cart' );
	}
} );
