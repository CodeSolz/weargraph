/**
 * WearGraph Try On button loader (kept tiny). Tracks the selected variation, and
 * lazy-loads the fitting room on the first click only.
 */
( function () {
	'use strict';

	var cfg = window.weargraphTryOn;
	if ( ! cfg ) {
		return;
	}

	var loading = null;
	var selected = {}; // product id -> variation id

	function load() {
		if ( window.WearGraphFittingRoom ) {
			return Promise.resolve();
		}
		if ( loading ) {
			return loading;
		}
		loading = new Promise( function ( resolve, reject ) {
			var css = document.createElement( 'link' );
			css.rel = 'stylesheet';
			css.href = cfg.modalCss;
			document.head.appendChild( css );

			var js = document.createElement( 'script' );
			js.src = cfg.modalJs;
			js.async = true;
			js.onload = function () {
				// Add-on scripts (weargraph_fitting_room_scripts) load after the fitting room.
				( cfg.extraJs || [] ).forEach( function ( src ) {
					var extra = document.createElement( 'script' );
					extra.src = src;
					extra.async = true;
					document.body.appendChild( extra );
				} );
				resolve();
			};
			js.onerror = function () {
				loading = null;
				reject( new Error( 'load' ) );
			};
			document.body.appendChild( js );
		} );
		return loading;
	}

	document.addEventListener( 'click', function ( event ) {
		var btn = event.target.closest ? event.target.closest( '.weargraph-tryon-button' ) : null;
		if ( ! btn ) {
			return;
		}
		event.preventDefault();
		var productId = parseInt( btn.getAttribute( 'data-weargraph-product' ), 10 );
		btn.setAttribute( 'aria-busy', 'true' );
		load().then( function () {
			btn.removeAttribute( 'aria-busy' );
			window.WearGraphFittingRoom.open( {
				productId: productId,
				variationId: selected[ productId ] || 0,
				trigger: btn,
				config: cfg
			} );
		} ).catch( function () {
			btn.removeAttribute( 'aria-busy' );
			window.alert( cfg.i18n.network );
		} );
	} );

	// Follow the variation chosen in the standard WooCommerce form (jQuery events).
	if ( window.jQuery ) {
		window.jQuery( document ).on( 'found_variation', 'form.variations_form', function ( e, variation ) {
			var pid = parseInt( this.getAttribute( 'data-product_id' ), 10 );
			if ( pid && variation && variation.variation_id ) {
				selected[ pid ] = variation.variation_id;
			}
		} ).on( 'reset_data', 'form.variations_form', function () {
			var pid = parseInt( this.getAttribute( 'data-product_id' ), 10 );
			if ( pid ) {
				selected[ pid ] = 0;
			}
		} );
	}

	// Selector fallback for themes without the standard product hooks.
	var fallback = document.querySelector( '.weargraph-tryon-fallback' );
	if ( fallback && cfg.selector ) {
		var anchor = null;
		try {
			anchor = document.querySelector( cfg.selector );
		} catch ( err ) {
			anchor = null;
		}
		var button = fallback.querySelector( '.weargraph-tryon-button' );
		if ( anchor && button && anchor.parentNode ) {
			anchor.parentNode.insertBefore( button, anchor.nextSibling );
		}
	}
}() );
