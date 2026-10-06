/**
 * WearGraph Catalog screen: start/stop scans and show live progress.
 */
( function () {
	'use strict';

	var cfg = window.weargraphCatalog;
	var root = document.querySelector( '.weargraph-catalog' );
	if ( ! cfg || ! root ) {
		return;
	}

	var t = cfg.i18n;
	var box = root.querySelector( '.weargraph-scan' );
	var bar = box.querySelector( '.weargraph-progress span' );
	var text = box.querySelector( '.weargraph-scan__text' );
	var timer = null;

	/**
	 * REST URL for a path that may carry its own query string. With plain permalinks the
	 * root already contains "?rest_route=", so the path's "?" must become "&".
	 */
	function restUrl( path ) {
		return cfg.root + ( cfg.root.indexOf( '?' ) !== -1 ? path.replace( '?', '&' ) : path );
	}

	function api( method, path, body ) {
		var opts = { method: method, credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce } };
		if ( body ) {
			opts.headers[ 'Content-Type' ] = 'application/json';
			opts.body = JSON.stringify( body );
		}
		return window.fetch( restUrl( path ), opts ).then( function ( res ) {
			if ( ! res.ok ) {
				throw new Error( res.status );
			}
			return res.json();
		} );
	}

	function render( state ) {
		box.hidden = state.status !== 'running';
		bar.style.width = ( state.percent || 0 ) + '%';
		text.textContent = t.progress.replace( '%1$s', state.processed || 0 ).replace( '%2$s', state.total || 0 );
	}

	function poll() {
		api( 'GET', 'catalog/scan' ).then( function ( state ) {
			render( state );
			if ( state.status === 'running' ) {
				timer = window.setTimeout( poll, 2000 );
			} else if ( state.status === 'done' ) {
				text.textContent = t.done;
				box.hidden = false;
				window.setTimeout( function () {
					window.location.reload();
				}, 800 );
			}
		} ).catch( function () {
			timer = window.setTimeout( poll, 5000 );
		} );
	}

	function start( force ) {
		window.clearTimeout( timer );
		api( 'POST', 'catalog/scan', { force: force } ).then( function ( state ) {
			render( state );
			timer = window.setTimeout( poll, 1000 );
		} ).catch( function () {
			window.alert( t.failed );
		} );
	}

	document.querySelectorAll( '[data-action="scan"]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			start( false );
		} );
	} );

	document.querySelectorAll( '[data-action="rescan-all"]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			if ( window.confirm( t.confirmAll ) ) {
				start( true );
			}
		} );
	} );

	root.querySelector( '[data-action="cancel-scan"]' ).addEventListener( 'click', function () {
		window.clearTimeout( timer );
		api( 'POST', 'catalog/scan/cancel' ).then( render );
	} );

	if ( root.getAttribute( 'data-status' ) === 'running' ) {
		poll();
	}
}() );
