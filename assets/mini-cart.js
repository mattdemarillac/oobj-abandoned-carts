/**
 * "Email me this cart" in WooCommerce's Mini-Cart drawer (OOBJ_AC_Mini_Cart): a link under its buttons to the form
 * on the cart page (#save-cart), which puts the cursor in the email field. Its address and text are on the hidden
 * .oobj-ac-mini-cart-link after the block. The drawer's contents are drawn when it opens, so the link is added
 * whenever a footer appears. The footer is there only when the cart has something in it.
 */
( function () {
	const data = document.querySelector( '.oobj-ac-mini-cart-link' );
	const miniCart = document.querySelector( '.wc-block-mini-cart' );
	if ( ! data || ! miniCart ) {
		return;
	}
	const config = { url: data.dataset.url, text: data.dataset.text };

	function addLink() {
		document.querySelectorAll( '.wc-block-mini-cart__footer' ).forEach( function ( footer ) {
			if ( footer.querySelector( '.oobj-ac-mini-cart' ) ) {
				return;
			}
			const link = document.createElement( 'a' );
			link.className = 'oobj-ac-mini-cart';
			link.href = config.url;
			link.textContent = config.text;
			footer.appendChild( link );
		} );
	}

	new window.MutationObserver( addLink ).observe( miniCart, { childList: true, subtree: true } );
	addLink();
}() );
