/**
 * WearGraph settings: the AI provider picker, key show/hide, Test connection and
 * Refresh models. Requests carry only the provider id, the field and a nonce —
 * saved keys never leave the server. Without this script every provider panel is
 * shown and the form still saves.
 */
( function () {
	'use strict';

	// Confirm destructive tools (forms carrying data-confirm).
	document.querySelectorAll( 'form[data-confirm]' ).forEach( function ( form ) {
		form.addEventListener( 'submit', function ( event ) {
			if ( ! window.confirm( form.getAttribute( 'data-confirm' ) ) ) {
				event.preventDefault();
			}
		} );
	} );

	var config = window.weargraphSettings;
	var root = document.querySelector( '.weargraph-providers' );
	if ( ! config || ! root ) {
		return;
	}

	root.classList.add( 'is-enhanced' );

	var cards = Array.prototype.slice.call( root.querySelectorAll( '.weargraph-provider-card' ) );
	var panels = root.querySelectorAll( '.weargraph-provider-panel' );
	var activeInput = root.querySelector( '[data-active-input]' );

	function activate( id, focusPanel ) {
		cards.forEach( function ( card ) {
			var on = card.getAttribute( 'data-provider' ) === id;
			card.classList.toggle( 'is-active', on );
			card.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
		} );
		panels.forEach( function ( panel ) {
			var on = panel.getAttribute( 'data-provider' ) === id;
			panel.classList.toggle( 'is-active', on );
			if ( on && focusPanel ) {
				var first = panel.querySelector( 'input, select, button' );
				if ( first ) {
					first.focus();
				}
			}
		} );
		if ( activeInput ) {
			activeInput.value = id;
		}
	}

	cards.forEach( function ( card, index ) {
		card.addEventListener( 'click', function () {
			activate( card.getAttribute( 'data-provider' ), false );
		} );
		// Arrow keys move between cards.
		card.addEventListener( 'keydown', function ( event ) {
			var step = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[ event.key ];
			if ( ! step ) {
				return;
			}
			event.preventDefault();
			var next = cards[ ( index + step + cards.length ) % cards.length ];
			next.focus();
			activate( next.getAttribute( 'data-provider' ), false );
		} );
	} );

	// Key show / hide.
	root.querySelectorAll( '[data-key-toggle]' ).forEach( function ( button ) {
		var input = document.getElementById( button.getAttribute( 'data-key-toggle' ) );
		if ( ! input ) {
			return;
		}
		button.hidden = false;
		button.addEventListener( 'click', function () {
			var show = 'password' === input.type;
			input.type = show ? 'text' : 'password';
			button.textContent = show ? config.i18n.hide : config.i18n.show;
			button.setAttribute( 'aria-pressed', show ? 'true' : 'false' );
		} );
	} );

	function show( el, ok, message ) {
		if ( ! el ) {
			return;
		}
		el.textContent = message;
		el.className = 'weargraph-test-result ' + ( null === ok ? '' : ( ok ? 'is-ok' : 'is-error' ) );
	}

	function post( fields ) {
		var body = new window.FormData();
		Object.keys( fields ).forEach( function ( key ) {
			body.append( key, fields[ key ] );
		} );
		return window.fetch( config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json();
			} );
	}

	// Test connection (the saved key, server side).
	root.querySelectorAll( '[data-test]' ).forEach( function ( button ) {
		var id = button.getAttribute( 'data-test' );
		var result = root.querySelector( '[data-test-result="' + id + '"]' );
		button.addEventListener( 'click', function () {
			button.disabled = true;
			show( result, null, config.i18n.testing );
			post( { action: 'weargraph_test_connection', nonce: config.nonce, provider: id } )
				.then( function ( json ) {
					var data = json && json.data ? json.data : {};
					var message = data.message || config.i18n.failed;
					if ( json && json.success && data.latency_ms ) {
						message += ' ' + config.i18n.latency.replace( '%d', data.latency_ms );
					}
					show( result, !! ( json && json.success ), message );
				} )
				.catch( function () {
					show( result, false, config.i18n.failed );
				} )
				.then( function () {
					button.disabled = false;
				} );
		} );
	} );

	// Refresh models: fill the select with the provider's current list.
	root.querySelectorAll( '[data-refresh]' ).forEach( function ( button ) {
		var id = button.getAttribute( 'data-refresh' );
		var field = button.getAttribute( 'data-refresh-field' );
		var panel = root.querySelector( '.weargraph-provider-panel[data-provider="' + id + '"]' );
		var select = panel ? panel.querySelector( 'select[data-field="' + field + '"]' ) : null;
		var result = root.querySelector( '[data-refresh-result="' + id + '-' + field + '"]' );
		button.addEventListener( 'click', function () {
			button.disabled = true;
			show( result, null, config.i18n.refreshing );
			post( { action: 'weargraph_refresh_models', nonce: config.models, provider: id, field: field } )
				.then( function ( json ) {
					var data = json && json.data ? json.data : {};
					if ( json && json.success && select && data.choices ) {
						var current = select.value;
						while ( select.options.length ) {
							select.remove( 0 );
						}
						Object.keys( data.choices ).forEach( function ( value ) {
							var option = document.createElement( 'option' );
							option.value = value;
							option.textContent = data.choices[ value ];
							option.selected = value === current;
							select.appendChild( option );
						} );
					}
					show( result, !! ( json && json.success ), data.message || config.i18n.failed );
				} )
				.catch( function () {
					show( result, false, config.i18n.failed );
				} )
				.then( function () {
					button.disabled = false;
				} );
		} );
	} );
}() );
