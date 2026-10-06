/**
 * WearGraph Studio (product edit screen).
 *
 * Talks only to WearGraph's REST routes with the logged-in user's nonce. No API
 * keys ever reach this script. All text is inserted with textContent.
 */
( function () {
	'use strict';

	var cfg = window.weargraphStudio;
	var root = document.querySelector( '.weargraph-studio' );
	if ( ! cfg || ! root ) {
		return;
	}

	var t = cfg.i18n;
	var POLL_MS = 2000;
	var SLOW_AFTER_MS = 90000;
	var GIVE_UP_MS = 300000;

	/* ------------------------------------------------------------------ */
	/* Helpers                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * REST URL for a path that may carry its own query string. With plain permalinks the
	 * root already contains "?rest_route=", so the path's "?" must become "&".
	 */
	function restUrl( path ) {
		return cfg.root + ( cfg.root.indexOf( '?' ) !== -1 ? path.replace( '?', '&' ) : path );
	}

	function api( method, path, body ) {
		var opts = {
			method: method,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce }
		};
		if ( body ) {
			opts.headers[ 'Content-Type' ] = 'application/json';
			opts.body = JSON.stringify( body );
		}
		return window.fetch( restUrl( path ), opts ).then( function ( res ) {
			return res.json().catch( function () {
				return {};
			} ).then( function ( json ) {
				if ( ! res.ok ) {
					var err = new Error( ( json && json.message ) || t.network );
					err.data = json;
					throw err;
				}
				return json;
			} );
		} );
	}

	function el( tag, attrs, text ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( key ) {
			node.setAttribute( key, attrs[ key ] );
		} );
		if ( text !== undefined ) {
			node.textContent = text;
		}
		return node;
	}

	function button( label, action, primary ) {
		return el( 'button', { type: 'button', class: 'button' + ( primary ? ' button-primary' : '' ), 'data-action': action }, label );
	}

	function setStatus( node, text, kind ) {
		if ( ! node ) {
			return;
		}
		node.textContent = text || '';
		node.className = 'weargraph-status' + ( kind ? ' is-' + kind : '' );
	}

	function money( value ) {
		return t.cost.replace( '%s', Number( value || 0 ).toFixed( 3 ) );
	}

	/**
	 * Poll a generation until it finishes.
	 * onTick(status) is called on every non-final poll.
	 */
	function poll( id, onTick ) {
		var started = Date.now();
		var slowShown = false;

		return new Promise( function ( resolve, reject ) {
			function tick() {
				api( 'GET', 'generations/' + id ).then( function ( gen ) {
					if ( gen.status === 'ready' || gen.status === 'failed' || gen.status === 'cancelled' ) {
						resolve( gen );
						return;
					}
					var elapsed = Date.now() - started;
					if ( elapsed > GIVE_UP_MS ) {
						resolve( gen );
						return;
					}
					onTick( gen, ! slowShown && elapsed > SLOW_AFTER_MS );
					if ( elapsed > SLOW_AFTER_MS ) {
						slowShown = true;
					}
					window.setTimeout( tick, POLL_MS );
				} ).catch( reject );
			}
			tick();
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Tabs                                                                 */
	/* ------------------------------------------------------------------ */

	var tabs = Array.prototype.slice.call( root.querySelectorAll( '.weargraph-studio__tab' ) );

	function selectTab( tab ) {
		tabs.forEach( function ( other ) {
			var active = other === tab;
			other.classList.toggle( 'is-active', active );
			other.setAttribute( 'aria-selected', active ? 'true' : 'false' );
			other.tabIndex = active ? 0 : -1; // Roving tabindex: Tab enters the tab list once.
			var panel = document.getElementById( other.getAttribute( 'aria-controls' ) );
			if ( panel ) {
				panel.hidden = ! active;
			}
		} );
	}

	tabs.forEach( function ( tab, i ) {
		tab.tabIndex = tab.getAttribute( 'aria-selected' ) === 'true' ? 0 : -1;
		tab.addEventListener( 'click', function () {
			selectTab( tab );
		} );
		// WAI-ARIA tabs pattern: arrows move between tabs, Home/End jump to the ends.
		tab.addEventListener( 'keydown', function ( e ) {
			var to = null;
			if ( e.key === 'ArrowRight' ) {
				to = tabs[ ( i + 1 ) % tabs.length ];
			} else if ( e.key === 'ArrowLeft' ) {
				to = tabs[ ( i - 1 + tabs.length ) % tabs.length ];
			} else if ( e.key === 'Home' ) {
				to = tabs[ 0 ];
			} else if ( e.key === 'End' ) {
				to = tabs[ tabs.length - 1 ];
			}
			if ( to ) {
				e.preventDefault();
				selectTab( to );
				to.focus();
			}
		} );
	} );

	/* ------------------------------------------------------------------ */
	/* Generate                                                             */
	/* ------------------------------------------------------------------ */

	var gen = root.querySelector( '.weargraph-gen' );
	if ( gen ) {
		initGenerate( gen );
	}

	function initGenerate( box ) {
		var status = box.querySelector( '.weargraph-actions .weargraph-status' );
		var btnGenerate = box.querySelector( '[data-action="generate"]' );
		var btnCancel = box.querySelector( '[data-action="cancel"]' );
		var resultBox = box.querySelector( '.weargraph-result' );
		var historyBox = box.querySelector( '.weargraph-history' );
		var running = null;

		function field( name ) {
			var checked = box.querySelector( '[data-field="' + name + '"]:checked' );
			if ( checked ) {
				return checked.value;
			}
			var node = box.querySelector( '[data-field="' + name + '"]' );
			return node ? node.value : '';
		}

		function syncVisibility() {
			var onModel = field( 'mode' ) === 'on_model';
			box.querySelector( '.weargraph-model' ).hidden = ! onModel;
			var bg = box.querySelector( '.weargraph-background' );
			bg.classList.toggle( 'is-disabled', field( 'scene' ) !== 'none' );
			bg.querySelector( 'select' ).disabled = field( 'scene' ) !== 'none';
		}
		box.addEventListener( 'change', syncVisibility );
		syncVisibility();

		function busy( on ) {
			btnGenerate.disabled = on || box.getAttribute( 'data-ready' ) !== '1';
			btnCancel.hidden = ! on;
		}

		function payload( regenerate ) {
			var refs = [];
			box.querySelectorAll( '[data-ref]:checked' ).forEach( function ( input ) {
				refs.push( parseInt( input.value, 10 ) );
			} );
			return {
				product_id: cfg.productId,
				references: refs,
				mode: field( 'mode' ),
				model: field( 'model' ),
				scene: field( 'scene' ),
				background: field( 'background' ),
				style: field( 'style' ),
				aspect_ratio: field( 'aspect_ratio' ),
				size: field( 'size' ),
				custom: field( 'custom' ),
				negative: field( 'negative' ),
				regenerate: !! regenerate
			};
		}

		// Controls added by extensions (weargraph_studio_generate_fields) travel as data-extra-field.
		function withExtras( body ) {
			box.querySelectorAll( '[data-extra-field]' ).forEach( function ( node ) {
				if ( ( node.type === 'radio' || node.type === 'checkbox' ) && ! node.checked ) {
					return;
				}
				body[ node.getAttribute( 'data-extra-field' ) ] = node.value;
			} );
			return body;
		}

		function start( request ) {
			busy( true );
			setStatus( status, t.queued );
			resultBox.hidden = true;

			request.then( function ( res ) {
				running = res.id;
				if ( res.cached ) {
					setStatus( status, t.cached, 'info' );
				}
				return poll( res.id, function ( g, slow ) {
					setStatus( status, slow ? t.slow : ( g.status === 'queued' ? t.queued : t.processing ) );
				} );
			} ).then( function ( g ) {
				finish( g );
			} ).catch( function ( err ) {
				setStatus( status, err.message || t.network, 'error' );
			} ).then( function () {
				running = null;
				busy( false );
				loadHistory();
			} );
		}

		function finish( g ) {
			if ( g.status === 'ready' ) {
				setStatus( status, g.cost_usd !== undefined ? money( g.cost_usd ) : '', 'ok' );
				showResult( g );
			} else if ( g.status === 'failed' ) {
				setStatus( status, ( g.error && g.error.message ) || t.failed, 'error' );
			} else if ( g.status === 'cancelled' ) {
				setStatus( status, t.cancelled );
			} else {
				setStatus( status, t.slow, 'info' );
			}
		}

		btnGenerate.addEventListener( 'click', function () {
			var body = withExtras( payload( false ) );
			if ( ! body.references.length ) {
				setStatus( status, t.noRefs, 'error' );
				return;
			}
			start( api( 'POST', 'studio/generate', body ) );
		} );

		btnCancel.addEventListener( 'click', function () {
			if ( running ) {
				api( 'POST', 'generations/' + running + '/cancel' ).catch( function () {} );
			}
		} );

		function showResult( g ) {
			resultBox.textContent = '';
			resultBox.hidden = false;
			( g.outputs || [] ).forEach( function ( out ) {
				var card = el( 'div', { class: 'weargraph-result__card' } );
				var img = el( 'img', { src: out.url, alt: '', loading: 'lazy' } );
				card.appendChild( img );

				var actions = el( 'div', { class: 'weargraph-result__actions' } );
				var note = el( 'span', { class: 'weargraph-status', role: 'status', 'aria-live': 'polite' } );
				[
					[ t.addGallery, 'gallery', true ],
					[ t.setFeatured, 'featured', false ],
					[ t.saveMedia, 'media', false ]
				].forEach( function ( def ) {
					var b = button( def[ 0 ], 'save', def[ 2 ] );
					b.addEventListener( 'click', function () {
						b.disabled = true;
						api( 'POST', 'studio/generations/' + g.id + '/save', { target: def[ 1 ], asset_id: out.id } ).then( function () {
							setStatus( note, def[ 1 ] === 'gallery' ? t.savedGallery : ( def[ 1 ] === 'featured' ? t.savedFeatured : t.saved ), 'ok' );
						} ).catch( function ( err ) {
							setStatus( note, err.message, 'error' );
						} ).then( function () {
							b.disabled = false;
						} );
					} );
					actions.appendChild( b );
				} );

				var regen = button( t.regenerate, 'regenerate' );
				regen.addEventListener( 'click', function () {
					start( api( 'POST', 'studio/generations/' + g.id + '/rerun' ) );
				} );
				actions.appendChild( regen );
				actions.appendChild( note );
				card.appendChild( actions );
				resultBox.appendChild( card );
			} );
		}

		// Filters set by extensions (Pro's filter bar) through the weargraph:studio-history-filter event.
		var historyQuery = {};

		function loadHistory() {
			var qs = 'product_id=' + encodeURIComponent( cfg.productId );
			Object.keys( historyQuery ).forEach( function ( key ) {
				if ( historyQuery[ key ] !== '' && historyQuery[ key ] !== undefined ) {
					qs += '&' + encodeURIComponent( key ) + '=' + encodeURIComponent( historyQuery[ key ] );
				}
			} );
			api( 'GET', 'studio/history?' + qs ).then( function ( res ) {
				renderHistory( res.items || [] );
				if ( historyQuery.page ) {
					renderPager( res.page || 1, res.pages || 1 );
				}
				document.dispatchEvent( new window.CustomEvent( 'weargraph:studio-history-loaded', { detail: { total: res.total, page: res.page, pages: res.pages } } ) );
			} ).catch( function ( err ) {
				historyBox.textContent = err.message;
			} );
		}

		function renderPager( page, pages ) {
			if ( pages < 2 ) {
				return;
			}
			var nav = el( 'div', { class: 'weargraph-history__pager' } );
			var newer = button( t.newer || '‹', 'newer' );
			var older = button( t.older || '›', 'older' );
			newer.disabled = page <= 1;
			older.disabled = page >= pages;
			newer.addEventListener( 'click', function () {
				historyQuery.page = page - 1;
				loadHistory();
			} );
			older.addEventListener( 'click', function () {
				historyQuery.page = page + 1;
				loadHistory();
			} );
			nav.appendChild( newer );
			nav.appendChild( el( 'span', {}, page + ' / ' + pages ) );
			nav.appendChild( older );
			historyBox.appendChild( nav );
		}

		document.addEventListener( 'weargraph:studio-history-filter', function ( e ) {
			historyQuery = e.detail || {};
			loadHistory();
		} );

		function renderHistory( items ) {
			historyBox.textContent = '';
			if ( ! items.length ) {
				historyBox.appendChild( el( 'p', { class: 'description' }, t.noHistory ) );
				return;
			}
			var list = el( 'ul', { class: 'weargraph-history__list' } );
			items.forEach( function ( item ) {
				var li = el( 'li', { class: 'weargraph-history__item is-' + item.status } );
				var thumb = el( 'div', { class: 'weargraph-history__thumb' } );
				if ( item.outputs && item.outputs[ 0 ] ) {
					var img = el( 'img', { src: item.outputs[ 0 ].url, alt: '', loading: 'lazy' } );
					img.addEventListener( 'error', function () {
						thumb.textContent = t.expired;
					} );
					thumb.appendChild( img );
				} else {
					thumb.textContent = item.status === 'failed' ? '✕' : '…';
				}
				li.appendChild( thumb );

				var meta = item.meta || {};
				// Kinds other than image_generate (e.g. Pro edits) name themselves with meta.label.
				var label = meta.label ? meta.label : [ meta.mode === 'on_model' ? '👤' : '📦', meta.scene && meta.scene !== '' ? meta.scene : meta.background, meta.style ]
					.filter( Boolean ).join( ' · ' );
				var info = el( 'div', { class: 'weargraph-history__info' } );
				info.appendChild( el( 'strong', {}, label ) );
				info.appendChild( el( 'span', {}, new Date( item.created_at ).toLocaleString() + ' · ' + money( item.cost_usd ) ) );
				if ( item.error && item.error.message ) {
					info.appendChild( el( 'span', { class: 'is-error' }, item.error.message ) );
				}
				li.appendChild( info );

				var actions = el( 'div', { class: 'weargraph-history__actions' } );
				if ( item.status === 'ready' ) {
					var show = button( t.show, 'show' );
					show.addEventListener( 'click', function () {
						showResult( item );
						resultBox.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
					} );
					actions.appendChild( show );
				}
				var rerun = button( t.regenerate, 'rerun' );
				rerun.addEventListener( 'click', function () {
					start( api( 'POST', 'studio/generations/' + item.id + '/rerun' ) );
				} );
				actions.appendChild( rerun );
				var del = button( t.delete, 'delete' );
				del.addEventListener( 'click', function () {
					if ( window.confirm( t.confirmDelete ) ) {
						api( 'DELETE', 'studio/generations/' + item.id ).then( loadHistory );
					}
				} );
				actions.appendChild( del );
				li.appendChild( actions );
				list.appendChild( li );
			} );
			historyBox.appendChild( list );
		}

		// Other tabs (e.g. Pro edits) announce new results so the history refreshes.
		document.addEventListener( 'weargraph:studio-changed', loadHistory );
		loadHistory();
	}

	/* ------------------------------------------------------------------ */
	/* Alt text                                                             */
	/* ------------------------------------------------------------------ */

	root.querySelectorAll( '.weargraph-alt' ).forEach( function ( item ) {
		var attachmentId = parseInt( item.getAttribute( 'data-attachment' ), 10 );
		var input = item.querySelector( '[data-alt]' );
		var status = item.querySelector( '.weargraph-status' );
		var suggest = item.querySelector( '[data-action="suggest-alt"]' );
		var apply = item.querySelector( '[data-action="apply-alt"]' );

		suggest.addEventListener( 'click', function () {
			suggest.disabled = true;
			setStatus( status, t.suggesting );
			api( 'POST', 'studio/alt-text', { product_id: cfg.productId, attachment_id: attachmentId } ).then( function ( res ) {
				return poll( res.id, function () {} );
			} ).then( function ( g ) {
				if ( g.status === 'ready' && g.data && g.data.alt ) {
					input.value = g.data.alt;
					input.focus();
					setStatus( status, t.reviewAlt, 'info' );
				} else {
					setStatus( status, ( g.error && g.error.message ) || t.failed, 'error' );
				}
			} ).catch( function ( err ) {
				setStatus( status, err.message, 'error' );
			} ).then( function () {
				suggest.disabled = false;
			} );
		} );

		apply.addEventListener( 'click', function () {
			apply.disabled = true;
			api( 'POST', 'studio/alt-text/apply', { product_id: cfg.productId, attachment_id: attachmentId, alt: input.value } ).then( function ( res ) {
				input.value = res.alt;
				setStatus( status, t.saved, 'ok' );
				var badge = item.querySelector( '.weargraph-badge--warn' );
				if ( badge && res.alt ) {
					badge.remove();
				}
			} ).catch( function ( err ) {
				setStatus( status, err.message, 'error' );
			} ).then( function () {
				apply.disabled = false;
			} );
		} );
	} );
	/* ------------------------------------------------------------------ */
	/* Try-On tab (readiness + overrides)                                   */
	/* ------------------------------------------------------------------ */

	var tryonBox = root.querySelector( '.weargraph-tryon-tab' );
	if ( tryonBox && cfg.catalog ) {
		initTryOn( tryonBox );
	}

	function initTryOn( box ) {
		var c = cfg.catalog;
		var ct = c.i18n;
		var path = 'catalog/products/' + box.getAttribute( 'data-product' );
		var loaded = false;
		var status;

		function select( options, current, onChange ) {
			var sel = el( 'select' );
			options.forEach( function ( opt ) {
				var o = el( 'option', { value: opt[ 0 ] }, opt[ 1 ] );
				if ( opt[ 0 ] === current ) {
					o.selected = true;
				}
				sel.appendChild( o );
			} );
			sel.addEventListener( 'change', function () {
				onChange( sel.value );
			} );
			return sel;
		}

		function labelled( label, control ) {
			var wrap = el( 'label', { class: 'weargraph-field' } );
			wrap.appendChild( document.createTextNode( label ) );
			wrap.appendChild( control );
			return wrap;
		}

		function save( changes ) {
			setStatus( status, ct.saving );
			api( 'POST', path, changes ).then( function ( d ) {
				render( d );
				setStatus( status, ct.saved, 'ok' );
			} ).catch( function ( err ) {
				setStatus( status, err.message, 'error' );
			} );
		}

		function render( d ) {
			box.textContent = '';

			var head = el( 'div', { class: 'weargraph-tryon-head' } );
			head.appendChild( el( 'span', { class: 'weargraph-status-pill is-' + d.status }, d.status_label ) );
			if ( d.scanned && d.status !== 'unsupported' ) {
				head.appendChild( el( 'span', { class: 'weargraph-score' }, ct.score + ': ' + d.score + '/100' ) );
			}
			head.appendChild( el( 'strong', { class: 'weargraph-tryon-state' }, d.enabled ? ct.currentlyOn : ct.currentlyOff ) );
			box.appendChild( head );

			if ( ! d.scanned ) {
				box.appendChild( el( 'p', {}, ct.notScanned ) );
			}

			if ( d.problems.length ) {
				var ul = el( 'ul', { class: 'weargraph-problems' } );
				d.problems.forEach( function ( p ) {
					ul.appendChild( el( 'li', {}, p ) );
				} );
				box.appendChild( ul );
			} else if ( d.scanned ) {
				box.appendChild( el( 'p', { class: 'weargraph-muted' }, ct.noProblems ) );
			}

			var grid = el( 'div', { class: 'weargraph-grid' } );
			grid.appendChild( labelled( ct.tryon, select(
				[ [ 'auto', ct.tryonAuto ], [ 'on', ct.tryonOn ], [ 'off', ct.tryonOff ] ],
				d.enabled_auto ? 'auto' : ( d.enabled ? 'on' : 'off' ),
				function ( v ) {
					save( { tryon: v } );
				}
			) ) );

			var cats = [ [ 'auto', ct.automatic.replace( '%s', d.category_auto ? ( d.category_label || '-' ) : '...' ) ] ];
			Object.keys( c.categories ).forEach( function ( k ) {
				cats.push( [ k, c.categories[ k ] ] );
			} );
			grid.appendChild( labelled( ct.category, select( cats, d.category_auto ? 'auto' : d.category, function ( v ) {
				save( { category: v } );
			} ) ) );

			var layers = [ [ 'auto', ct.automatic.replace( '%s', d.layer_auto && c.layers[ d.layer ] ? c.layers[ d.layer ] : '...' ) ] ];
			Object.keys( c.layers ).forEach( function ( k ) {
				layers.push( [ k, c.layers[ k ] ] );
			} );
			grid.appendChild( labelled( ct.layer, select( layers, d.layer_auto ? 'auto' : d.layer, function ( v ) {
				save( { layer: v } );
			} ) ) );
			box.appendChild( grid );

			if ( d.images.length ) {
				box.appendChild( el( 'h4', {}, ct.sourceImage ) );
				var imgs = el( 'div', { class: 'weargraph-refs weargraph-source-picker' } );
				d.images.forEach( function ( img ) {
					var lab = el( 'label', { class: 'weargraph-ref' } );
					var radio = el( 'input', { type: 'radio', name: 'weargraph_best_' + d.product_id, value: String( img.id ), form: 'weargraph-studio-detached' } );
					radio.checked = img.id === d.best_image_id;
					radio.addEventListener( 'change', function () {
						save( { best_image_id: img.id } );
					} );
					lab.appendChild( radio );
					lab.appendChild( el( 'img', { src: img.thumbnail, alt: '' } ) );
					if ( img.score !== null ) {
						lab.appendChild( el( 'span', { class: 'weargraph-ref__score' }, String( img.score ) ) );
					}
					imgs.appendChild( lab );
				} );
				box.appendChild( imgs );
				if ( ! d.best_auto ) {
					var autoBtn = button( ct.autoImage, 'auto-image' );
					autoBtn.addEventListener( 'click', function () {
						save( { best_image_id: 'auto' } );
					} );
					box.appendChild( autoBtn );
				}
			}

			if ( d.variants.length ) {
				box.appendChild( el( 'h4', {}, ct.variants ) );
				var vl = el( 'ul', { class: 'weargraph-variants' } );
				d.variants.forEach( function ( v ) {
					var src = d.images.filter( function ( i ) {
						return i.id === v.best_image_id;
					} )[ 0 ];
					var li = el( 'li' );
					if ( src ) {
						li.appendChild( el( 'img', { src: src.thumbnail, alt: '' } ) );
					}
					li.appendChild( el( 'span', {}, v.label ) );
					vl.appendChild( li );
				} );
				box.appendChild( vl );
			}

			var actions = el( 'p', { class: 'weargraph-actions' } );
			var rescan = button( d.scanned ? ct.rescan : ct.scanNow, 'rescan', ! d.scanned );
			rescan.addEventListener( 'click', function () {
				save( {} );
			} );
			actions.appendChild( rescan );
			var reset = button( ct.reset, 'reset' );
			reset.addEventListener( 'click', function () {
				save( { reset: true } );
			} );
			actions.appendChild( reset );
			status = el( 'span', { class: 'weargraph-status', role: 'status', 'aria-live': 'polite' } );
			actions.appendChild( status );
			box.appendChild( actions );
			box.appendChild( el( 'p', { class: 'description' }, ct.storeNote ) );
		}

		function load() {
			if ( loaded ) {
				return;
			}
			loaded = true;
			api( 'GET', path ).then( render ).catch( function ( err ) {
				box.textContent = err.message;
				loaded = false;
			} );
		}

		var tab = root.querySelector( '[data-tab="tryon"]' );
		if ( tab ) {
			tab.addEventListener( 'click', load );
		}
		if ( ! box.closest( '[hidden]' ) ) {
			load();
		}
	}
}() );
