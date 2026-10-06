/**
 * WearGraph welcome notice: remember WordPress's own dismiss (×) click.
 */
( function () {
	'use strict';

	var cfg = window.weargraphNotices;
	if ( ! cfg ) {
		return;
	}

	document.addEventListener( 'click', function ( e ) {
		var target = e.target;
		if ( ! target || ! target.classList || ! target.classList.contains( 'notice-dismiss' ) ) {
			return;
		}
		var notice = target.closest( '[data-weargraph-notice]' );
		if ( ! notice ) {
			return;
		}

		var body = new window.FormData();
		body.append( 'action', cfg.action );
		body.append( 'nonce', cfg.nonce );
		window.fetch( cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } ).catch( function () {} );
	} );
}() );
