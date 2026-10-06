/**
 * WearGraph fitting room (loaded on first click of a Try On button).
 *
 * Talks only to WearGraph's storefront REST routes with the fitting-room session
 * cookie + CSRF header, and to WooCommerce's own add-to-cart endpoint. No API keys,
 * no third-party requests. All text is inserted with textContent.
 */
( function () {
	'use strict';

	var cfg = null;
	var t = {};
	var state = {};
	var root = null;
	var body = null;
	var lastFocus = null;
	var POLL_MS = 2000;
	var MAX_WAIT_MS = 150000;
	var MIN_SIDE = 512;
	var UPLOAD_SIDE = 1600;

	/* ------------------------------------------------------------------ */
	/* Helpers                                                              */
	/* ------------------------------------------------------------------ */

	function el( tag, attrs, text ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( key ) {
			if ( key === 'className' ) {
				node.className = attrs[ key ];
			} else {
				node.setAttribute( key, attrs[ key ] );
			}
		} );
		if ( text !== undefined && text !== null ) {
			node.textContent = text;
		}
		return node;
	}

	function btn( label, primary, onClick ) {
		var b = el( 'button', { type: 'button', className: 'wgfr__btn' + ( primary ? ' wgfr__btn--primary' : '' ) }, label );
		b.addEventListener( 'click', onClick );
		return b;
	}

	/**
	 * REST URL for a path that may carry its own query string. With plain permalinks the
	 * root already contains "?rest_route=", so the path's "?" must become "&".
	 */
	function restUrl( path ) {
		return cfg.root + ( cfg.root.indexOf( '?' ) !== -1 ? path.replace( '?', '&' ) : path );
	}

	function api( method, path, payload ) {
		var opts = { method: method, credentials: 'same-origin', headers: {} };
		if ( state.session && state.session.csrf ) {
			opts.headers[ 'X-WearGraph-CSRF' ] = state.session.csrf;
		}
		if ( payload instanceof window.FormData ) {
			opts.body = payload;
		} else if ( payload ) {
			opts.headers[ 'Content-Type' ] = 'application/json';
			opts.body = JSON.stringify( payload );
		}
		return window.fetch( restUrl( path ), opts ).then( function ( res ) {
			return res.json().catch( function () {
				return {};
			} ).then( function ( json ) {
				if ( ! res.ok ) {
					var err = new Error( ( json && json.message ) || t.network );
					err.code = json && json.code;
					throw err;
				}
				return json;
			} );
		}, function () {
			throw new Error( t.network );
		} );
	}

	function view( children, name ) {
		body.textContent = '';
		// The panel's layout follows the view (the result view is wide, two columns).
		body.parentNode.setAttribute( 'data-view', name || '' );
		children.forEach( function ( child ) {
			if ( child ) {
				body.appendChild( child );
			}
		} );
		var first = body.querySelector( 'button:not([disabled]), input, select, [tabindex]:not([tabindex="-1"])' );
		// A view with no control (loading, a message) takes focus itself, so focus is never lost.
		( first || body ).focus( { preventScroll: true } );

		// Add-ons (weargraph_fitting_room_scripts) may add to a view. They get read-only
		// copies of the session and product; their own requests go to their own routes.
		document.dispatchEvent( new window.CustomEvent( 'weargraph:fitting-room-view', {
			detail: {
				view: name || '',
				body: body,
				session: JSON.parse( JSON.stringify( state.session || {} ) ),
				product: JSON.parse( JSON.stringify( state.product || {} ) ),
				variation: state.variationId || 0,
				generation: JSON.parse( JSON.stringify( state.generation || {} ) )
			}
		} ) );
	}

	function message( text, kind ) {
		return el( 'p', { className: 'wgfr__msg' + ( kind ? ' wgfr__msg--' + kind : '' ), role: kind === 'error' ? 'alert' : 'status' }, text );
	}

	function spinner( text ) {
		var wrap = el( 'div', { className: 'wgfr__wait' } );
		wrap.appendChild( el( 'span', { className: 'wgfr__spinner', 'aria-hidden': 'true' } ) );
		wrap.appendChild( el( 'p', { className: 'wgfr__wait-text', role: 'status' }, text ) );
		return wrap;
	}

	/* ------------------------------------------------------------------ */
	/* Dialog                                                               */
	/* ------------------------------------------------------------------ */

	function build() {
		root = el( 'div', { className: 'wgfr', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'wgfr-title' } );
		var backdrop = el( 'div', { className: 'wgfr__backdrop' } );
		backdrop.addEventListener( 'click', close );
		var panel = el( 'div', { className: 'wgfr__panel' } );
		var head = el( 'div', { className: 'wgfr__head' } );
		head.appendChild( el( 'h2', { id: 'wgfr-title', className: 'wgfr__title' }, t.title ) );
		var x = el( 'button', { type: 'button', className: 'wgfr__close', 'aria-label': t.close }, '×' );
		x.addEventListener( 'click', close );
		head.appendChild( x );
		body = el( 'div', { className: 'wgfr__body', tabindex: '-1' } );
		panel.appendChild( head );
		panel.appendChild( body );
		root.appendChild( backdrop );
		root.appendChild( panel );
		root.addEventListener( 'keydown', onKey );
		document.body.appendChild( root );
	}

	function onKey( e ) {
		if ( e.key === 'Escape' ) {
			e.preventDefault();
			close();
			return;
		}
		if ( e.key !== 'Tab' ) {
			return;
		}
		var nodes = Array.prototype.filter.call(
			root.querySelectorAll( 'button, [href], input, select, textarea, video, [tabindex]:not([tabindex="-1"])' ),
			function ( n ) {
				return ! n.disabled && n.offsetParent !== null;
			}
		);
		if ( ! nodes.length ) {
			return;
		}
		var first = nodes[ 0 ];
		var last = nodes[ nodes.length - 1 ];
		if ( e.shiftKey && document.activeElement === first ) {
			e.preventDefault();
			last.focus();
		} else if ( ! e.shiftKey && document.activeElement === last ) {
			e.preventDefault();
			first.focus();
		}
	}

	function open( options ) {
		cfg = options.config;
		t = cfg.i18n;
		lastFocus = options.trigger || document.activeElement;
		state = { productId: options.productId, variationId: options.variationId || 0, session: null, product: null, generation: null, compare: false };
		if ( ! root ) {
			build();
		}
		root.hidden = false;
		document.documentElement.classList.add( 'wgfr-open' );
		view( [ spinner( t.loading ) ] );
		root.querySelector( '.wgfr__close' ).focus();

		api( 'GET', 'storefront/session?product_id=' + encodeURIComponent( state.productId ) ).then( function ( data ) {
			if ( ! data.available || ! data.product ) {
				view( [ message( t.unavailable ) ] );
				return;
			}
			state.session = data.session;
			state.product = data.product;
			if ( state.variationId && ! variation( state.variationId ) ) {
				state.variationId = 0;
			}
			next();
		} ).catch( function ( err ) {
			view( [ message( err.message, 'error' ) ] );
		} );
	}

	function close() {
		stopCamera();
		if ( root ) {
			root.hidden = true;
		}
		document.documentElement.classList.remove( 'wgfr-open' );
		state.closed = true;
		if ( lastFocus && lastFocus.focus ) {
			lastFocus.focus();
		}
	}

	/* ------------------------------------------------------------------ */
	/* Flow                                                                 */
	/* ------------------------------------------------------------------ */

	function variation( id ) {
		return ( state.product.variations || [] ).filter( function ( v ) {
			return v.id === id;
		} )[ 0 ];
	}

	function tryable() {
		return ( state.product.variations || [] ).filter( function ( v ) {
			return v.can_try;
		} );
	}

	function next() {
		if ( ! state.session.has_photo ) {
			photoView();
			return;
		}
		if ( state.product.type === 'variable' && ( ! state.variationId || ! ( variation( state.variationId ) || {} ).can_try ) ) {
			optionView();
			return;
		}
		generate( false );
	}

	/* --- Photo step --------------------------------------------------- */

	function photoView( notice ) {
		var guide = el( 'div', { className: 'wgfr__guide', 'aria-hidden': 'true' } );
		guide.appendChild( el( 'span', { className: 'wgfr__silhouette' } ) );

		var input = el( 'input', { type: 'file', accept: 'image/jpeg,image/png,image/webp', className: 'wgfr__file', id: 'wgfr-file' } );
		var upload = el( 'label', { className: 'wgfr__btn wgfr__btn--primary', 'for': 'wgfr-file', tabindex: '0', role: 'button' }, t.upload );
		upload.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' || e.key === ' ' ) {
				e.preventDefault();
				input.click();
			}
		} );
		input.addEventListener( 'change', function () {
			if ( input.files && input.files[ 0 ] ) {
				inspect( input.files[ 0 ] );
			}
		} );

		var actions = el( 'div', { className: 'wgfr__actions' } );
		actions.appendChild( upload );
		actions.appendChild( input );
		if ( navigator.mediaDevices && navigator.mediaDevices.getUserMedia ) {
			actions.appendChild( btn( t.camera, false, cameraView ) );
		}

		view( [
			el( 'h3', { className: 'wgfr__step' }, t.stepPhoto ),
			notice ? message( notice.text, notice.kind ) : null,
			guide,
			el( 'p', { className: 'wgfr__help' }, t.photoHelp ),
			actions
		] );
	}

	var stream = null;

	function stopCamera() {
		if ( stream ) {
			stream.getTracks().forEach( function ( track ) {
				track.stop();
			} );
			stream = null;
		}
	}

	function cameraView() {
		var video = el( 'video', { className: 'wgfr__video', autoplay: '', playsinline: '', muted: '' } );
		video.muted = true;
		var actions = el( 'div', { className: 'wgfr__actions' } );
		actions.appendChild( btn( t.capture, true, function () {
			var c = document.createElement( 'canvas' );
			c.width = video.videoWidth;
			c.height = video.videoHeight;
			c.getContext( '2d' ).drawImage( video, 0, 0 );
			stopCamera();
			c.toBlob( function ( blob ) {
				if ( blob ) {
					inspect( blob );
				}
			}, 'image/jpeg', 0.92 );
		} ) );
		actions.appendChild( btn( t.cancel, false, function () {
			stopCamera();
			photoView();
		} ) );
		view( [ el( 'h3', { className: 'wgfr__step' }, t.stepPhoto ), video, actions ] );

		navigator.mediaDevices.getUserMedia( { video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 1600 } }, audio: false } ).then( function ( s ) {
			stream = s;
			video.srcObject = s;
		} ).catch( function () {
			photoView( { text: t.noCamera, kind: 'error' } );
		} );
	}

	/** Validate locally, then show a preview with consent. */
	function inspect( file ) {
		if ( file.type && ! /^image\/(jpeg|png|webp)$/.test( file.type ) ) {
			photoView( { text: t.notImage, kind: 'error' } );
			return;
		}
		var url = URL.createObjectURL( file );
		var img = new Image();
		img.onload = function () {
			var w = img.naturalWidth;
			var h = img.naturalHeight;
			if ( Math.min( w, h ) < MIN_SIDE ) {
				URL.revokeObjectURL( url );
				photoView( { text: t.tooSmall, kind: 'error' } );
				return;
			}
			var warnings = [];
			var probe = document.createElement( 'canvas' );
			probe.width = 48;
			probe.height = 48;
			var ctx = probe.getContext( '2d' );
			ctx.drawImage( img, 0, 0, 48, 48 );
			var data = ctx.getImageData( 0, 0, 48, 48 ).data;
			var sum = 0;
			for ( var i = 0; i < data.length; i += 4 ) {
				sum += 0.299 * data[ i ] + 0.587 * data[ i + 1 ] + 0.114 * data[ i + 2 ];
			}
			var mean = sum / ( data.length / 4 );
			if ( mean < 45 ) {
				warnings.push( t.tooDark );
			} else if ( mean > 235 ) {
				warnings.push( t.tooBright );
			}
			if ( w > h * 1.1 ) {
				warnings.push( t.landscape );
			}
			previewView( img, url, warnings );
		};
		img.onerror = function () {
			URL.revokeObjectURL( url );
			photoView( { text: t.notImage, kind: 'error' } );
		};
		img.src = url;
	}

	function previewView( img, url, warnings ) {
		var figure = el( 'div', { className: 'wgfr__preview' } );
		var shown = el( 'img', { src: url, alt: t.yourPhoto } );
		figure.appendChild( shown );

		var nodes = [ el( 'h3', { className: 'wgfr__step' }, t.stepPhoto ), figure ];
		warnings.forEach( function ( w ) {
			nodes.push( message( w, 'warn' ) );
		} );

		var go = btn( t.usePhoto, true, function () {
			go.disabled = true;
			upload( img, url, consentBox ? consentBox.checked : true );
		} );

		var consentBox = null;
		if ( ! state.session.consent ) {
			var consent = el( 'div', { className: 'wgfr__consent' } );
			var label = el( 'label' );
			consentBox = el( 'input', { type: 'checkbox' } );
			consentBox.addEventListener( 'change', function () {
				go.disabled = ! consentBox.checked;
			} );
			label.appendChild( consentBox );
			label.appendChild( document.createTextNode( ' ' + state.session.consent_md ) );
			consent.appendChild( label );
			var small = el( 'p', { className: 'wgfr__fine' }, state.session.keep_note || ( state.session.retention === 'day' ? t.retentionDay : t.retentionSess ) );
			if ( state.session.privacy ) {
				small.appendChild( document.createTextNode( ' ' ) );
				small.appendChild( el( 'a', { href: state.session.privacy, target: '_blank', rel: 'noopener' }, t.privacyLink ) );
			}
			consent.appendChild( small );
			nodes.push( consent );
			go.disabled = true;
		}

		var actions = el( 'div', { className: 'wgfr__actions' } );
		actions.appendChild( go );
		actions.appendChild( btn( t.choose, false, function () {
			URL.revokeObjectURL( url );
			photoView();
		} ) );
		nodes.push( actions );
		view( nodes );
	}

	/** Downscale and re-encode as JPEG in the browser (drops EXIF/GPS), then upload. */
	function upload( img, url, consented ) {
		var scale = Math.min( 1, UPLOAD_SIDE / Math.max( img.naturalWidth, img.naturalHeight ) );
		var c = document.createElement( 'canvas' );
		c.width = Math.round( img.naturalWidth * scale );
		c.height = Math.round( img.naturalHeight * scale );
		var ctx = c.getContext( '2d' );
		ctx.fillStyle = '#fff';
		ctx.fillRect( 0, 0, c.width, c.height );
		ctx.drawImage( img, 0, 0, c.width, c.height );
		URL.revokeObjectURL( url );

		view( [ spinner( t.uploading ) ] );
		c.toBlob( function ( blob ) {
			var form = new window.FormData();
			form.append( 'photo', blob, 'photo.jpg' );
			form.append( 'consent', consented ? '1' : '0' );
			api( 'POST', 'storefront/photo', form ).then( function ( session ) {
				state.session = session;
				next();
			} ).catch( function ( err ) {
				photoView( { text: err.message, kind: 'error' } );
			} );
		}, 'image/jpeg', 0.9 );
	}

	/* --- Option step -------------------------------------------------- */

	function optionView() {
		var list = el( 'div', { className: 'wgfr__options', role: 'list' } );
		( state.product.variations || [] ).forEach( function ( v ) {
			var b = el( 'button', { type: 'button', className: 'wgfr__option', role: 'listitem' } );
			if ( v.thumbnail ) {
				b.appendChild( el( 'img', { src: v.thumbnail, alt: '' } ) );
			}
			b.appendChild( el( 'span', {}, v.label + ( v.can_try ? '' : ' (' + t.cannotTry + ')' ) ) );
			b.disabled = ! v.can_try;
			b.addEventListener( 'click', function () {
				state.variationId = v.id;
				generate( false );
			} );
			list.appendChild( b );
		} );
		view( [ el( 'h3', { className: 'wgfr__step' }, t.chooseOption ), list ] );
	}

	/* --- Generate ----------------------------------------------------- */

	function generate( regenerate ) {
		view( [ spinner( t.generating ) ] );
		api( 'POST', 'storefront/tryons', { product_id: state.productId, variation_id: state.variationId, regenerate: !! regenerate } ).then( function ( res ) {
			state.session.tryons = res.tryons;
			state.session.regens = res.regens;
			return wait( res.id );
		} ).then( function ( gen ) {
			if ( state.closed ) {
				return;
			}
			if ( gen.status === 'ready' && gen.outputs && gen.outputs[ 0 ] ) {
				state.generation = gen;
				state.compare = false;
				resultView();
			} else {
				errorView( ( gen.error && gen.error.message ) || t.failed );
			}
		} ).catch( function ( err ) {
			if ( err.code === 'weargraph_photo_required' ) {
				state.session.has_photo = false;
				photoView( { text: err.message, kind: 'error' } );
				return;
			}
			errorView( err.message );
		} );
	}

	function wait( id ) {
		var started = Date.now();
		return new Promise( function ( resolve, reject ) {
			function tick() {
				if ( state.closed ) {
					resolve( { status: 'cancelled' } );
					return;
				}
				api( 'GET', 'generations/' + id ).then( function ( gen ) {
					if ( gen.status === 'ready' || gen.status === 'failed' || gen.status === 'cancelled' || Date.now() - started > MAX_WAIT_MS ) {
						resolve( gen );
						return;
					}
					if ( Date.now() - started > 45000 ) {
						var text = body.querySelector( '.wgfr__wait-text' );
						if ( text ) {
							text.textContent = t.stillWorking;
						}
					}
					window.setTimeout( tick, POLL_MS );
				} ).catch( reject );
			}
			tick();
		} );
	}

	/* --- Result ------------------------------------------------------- */

	function resultView( notice ) {
		var gen = state.generation;
		var figure = el( 'figure', { className: 'wgfr__result' } );
		var img = el( 'img', { src: state.compare ? state.session.photo_url : gen.outputs[ 0 ].url, alt: state.compare ? t.yourPhoto : t.preview + ': ' + state.product.name } );
		figure.appendChild( img );
		figure.appendChild( el( 'figcaption', { className: 'wgfr__caption' }, t.caption ) );

		var info = el( 'div', { className: 'wgfr__info' } );
		info.appendChild( el( 'strong', { className: 'wgfr__name' }, state.product.name ) );
		var v = state.variationId ? variation( state.variationId ) : null;
		info.appendChild( el( 'span', { className: 'wgfr__price' }, v ? v.price : state.product.price ) );

		var nodes = [ figure, info ];

		var options = tryable();
		if ( options.length > 1 ) {
			var sel = el( 'select', { className: 'wgfr__select', 'aria-label': t.colour } );
			options.forEach( function ( o ) {
				var opt = el( 'option', { value: String( o.id ) }, o.label );
				if ( o.id === state.variationId ) {
					opt.selected = true;
				}
				sel.appendChild( opt );
			} );
			sel.addEventListener( 'change', function () {
				state.variationId = parseInt( sel.value, 10 );
				api( 'POST', 'storefront/events', { name: 'variant_changed', product_id: state.productId, variation_id: state.variationId } ).catch( function () {} );
				generate( false );
			} );
			var wrap = el( 'label', { className: 'wgfr__field' }, t.colour + ' ' );
			wrap.appendChild( sel );
			nodes.push( wrap );
		}

		if ( notice ) {
			nodes.push( notice );
		}

		var primary = el( 'div', { className: 'wgfr__actions' } );
		var cart = btn( t.addToCart, true, function () {
			addToCart( cart );
		} );
		if ( v && v.needs_form ) {
			cart.disabled = true;
			nodes.push( message( t.chooseOnPage ) );
		}
		primary.appendChild( cart );
		if ( state.session.regens > 0 ) {
			primary.appendChild( btn( t.regenerate, false, function () {
				generate( true );
			} ) );
		}
		nodes.push( primary );

		var secondary = el( 'div', { className: 'wgfr__actions wgfr__actions--secondary' } );
		if ( state.session.photo_url ) {
			secondary.appendChild( btn( state.compare ? t.showResult : t.compare, false, function () {
				state.compare = ! state.compare;
				resultView();
			} ) );
		}
		secondary.appendChild( btn( t.changePhoto, false, function () {
			photoView();
		} ) );
		secondary.appendChild( btn( t.deletePhotos, false, deletePhotos ) );
		nodes.push( secondary );
		nodes.push( el( 'p', { className: 'wgfr__fine' }, t.triesLeft.replace( '%d', state.session.tryons ) ) );

		view( nodes, 'result' );
	}

	function addToCart( button ) {
		if ( ! cfg.ajaxCart ) {
			window.location.href = state.product.url;
			return;
		}
		button.disabled = true;
		button.textContent = t.adding;
		var form = new window.FormData();
		form.append( 'product_id', String( state.variationId || state.productId ) );
		form.append( 'quantity', '1' );
		window.fetch( cfg.ajaxCart, { method: 'POST', body: form, credentials: 'same-origin' } ).then( function ( res ) {
			return res.json();
		} ).then( function ( json ) {
			if ( ! json || json.error ) {
				resultView( message( t.chooseOnPage, 'error' ) );
				return;
			}
			if ( window.jQuery ) {
				window.jQuery( document.body ).trigger( 'added_to_cart', [ json.fragments, json.cart_hash, window.jQuery( button ) ] );
			}
			var done = message( t.added, 'ok' );
			if ( cfg.cartUrl ) {
				done.appendChild( document.createTextNode( ' ' ) );
				done.appendChild( el( 'a', { href: cfg.cartUrl }, t.viewCart ) );
			}
			resultView( done );
		} ).catch( function () {
			resultView( message( t.network, 'error' ) );
		} );
	}

	function deletePhotos() {
		if ( ! window.confirm( t.confirmDelete ) ) {
			return;
		}
		api( 'DELETE', 'storefront/photo' ).then( function ( res ) {
			state.session = res.session;
			state.generation = null;
			photoView( { text: t.deleted, kind: 'ok' } );
		} ).catch( function ( err ) {
			errorView( err.message );
		} );
	}

	function errorView( text ) {
		var actions = el( 'div', { className: 'wgfr__actions' } );
		actions.appendChild( btn( t.regenerate, true, function () {
			next();
		} ) );
		actions.appendChild( btn( t.changePhoto, false, function () {
			photoView();
		} ) );
		view( [ message( text || t.failed, 'error' ), actions ] );
	}

	window.WearGraphFittingRoom = { open: open, close: close };
}() );
