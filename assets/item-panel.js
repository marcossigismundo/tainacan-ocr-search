/* global TainacanOCRItem, wp */
/**
 * Quadro "Texto dos arquivos (OCR)" no formulário de edição do item.
 *
 * Integração com a SPA do Tainacan pelos ganchos @wordpress/hooks
 * (tainacan_navigation_path_updated / tainacan_item_edition_item_loaded),
 * sem observar o DOM nem mexer nos componentes Vue. O quadro é recriado
 * pelo Vue quando o formulário muda, então os eventos são delegados em
 * document e as referências são buscadas a cada renderização.
 */
( function () {
	'use strict';

	const cfg = window.TainacanOCRItem || {};
	const t = cfg.i18n || {};
	const NS = 'tainacan-ocr-search/item-panel';

	const state = {
		itemId: 0,
		data: null,
		timer: 0,
		openText: {}, // attachment id -> true
		texts: {}, // attachment id -> pages
		confirming: 0,
		busy: false,
		error: '',
	};

	if ( ! window.wp || ! wp.apiFetch ) {
		return;
	}

	function api( path, options ) {
		return wp.apiFetch( Object.assign( { path: cfg.restPath + path }, options || {} ) );
	}

	function esc( value ) {
		return String( value == null ? '' : value )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#39;' );
	}

	function fmt( template ) {
		const args = Array.prototype.slice.call( arguments, 1 );
		let i = 0;
		return String( template || '' )
			.replace( /%(\d)\$[sd]/g, function ( m, n ) { return args[ parseInt( n, 10 ) - 1 ]; } )
			.replace( /%[sd]/g, function () { return args[ i++ ]; } );
	}

	function number( n ) {
		try {
			return Number( n ).toLocaleString( 'pt-BR' );
		} catch ( e ) {
			return String( n );
		}
	}

	/* ---- Rota / item ------------------------------------------------------ */

	function itemIdFromPath( path ) {
		const m = String( path || '' ).match( /collections\/(\d+)\/items\/(\d+)\/edit/ );
		return m ? parseInt( m[ 2 ], 10 ) : 0;
	}

	function enter( itemId ) {
		if ( ! itemId ) {
			return leave();
		}
		if ( itemId !== state.itemId ) {
			state.itemId = itemId;
			state.data = null;
			state.openText = {};
			state.texts = {};
			state.confirming = 0;
		}
		load();
	}

	function leave() {
		state.itemId = 0;
		state.data = null;
		clearTimeout( state.timer );
	}

	if ( wp.hooks && wp.hooks.addAction ) {
		wp.hooks.addAction( 'tainacan_navigation_path_updated', NS, function ( payload ) {
			const path = ( payload && payload.childEntity && payload.childEntity.defaultLink ) ||
				( payload && payload.currentRoute && payload.currentRoute.path ) || '';
			enter( itemIdFromPath( path ) );
		} );
		wp.hooks.addAction( 'tainacan_item_edition_item_loaded', NS, function ( collection, item ) {
			enter( parseInt( item && item.id, 10 ) || state.itemId );
		} );
	}
	// Reserva para versões do Tainacan sem os ganchos: a rota fica no hash.
	window.addEventListener( 'hashchange', function () {
		enter( itemIdFromPath( window.location.hash ) );
	} );
	document.addEventListener( 'DOMContentLoaded', function () {
		enter( itemIdFromPath( window.location.hash ) );
	} );

	/* ---- Dados ------------------------------------------------------------ */

	function load() {
		clearTimeout( state.timer );
		const itemId = state.itemId;
		if ( ! itemId ) {
			return;
		}
		api( 'item/' + itemId ).then( function ( data ) {
			if ( itemId !== state.itemId ) {
				return;
			}
			state.data = data;
			render();
			schedule();
		} ).catch( function () {
			if ( itemId !== state.itemId ) {
				return;
			}
			renderError();
			state.timer = setTimeout( load, 30000 );
		} );
	}

	function schedule() {
		const files = ( state.data && state.data.files ) || [];
		const active = files.some( function ( f ) {
			return 'queued' === f.status || 'processing' === f.status;
		} );
		// Rápido enquanto há OCR andando; devagar para notar arquivos enviados.
		state.timer = setTimeout( load, active ? 5000 : 20000 );
	}

	function run( ids, force ) {
		if ( state.busy || ! state.itemId ) {
			return;
		}
		state.busy = true;
		state.error = '';
		render();
		api( 'item/' + state.itemId + '/ocr', { method: 'POST', data: { attachment_ids: ids || [], force: !! force } } ).then( function ( data ) {
			state.data = data;
		} ).catch( function () {
			// Fica na tela até a próxima ação (o render seguinte não apaga).
			state.error = t.runError;
		} ).then( function () {
			state.busy = false;
			state.confirming = 0;
			render();
			clearTimeout( state.timer );
			state.timer = setTimeout( load, 3000 );
		} );
	}

	function toggleText( id ) {
		if ( state.openText[ id ] ) {
			delete state.openText[ id ];
			return render();
		}
		state.openText[ id ] = true;
		render();
		if ( state.texts[ id ] ) {
			return;
		}
		api( 'item/' + state.itemId + '/text/' + id ).then( function ( data ) {
			state.texts[ id ] = ( data && data.pages ) || [];
			render();
		} ).catch( function () {
			state.texts[ id ] = [];
			render();
		} );
	}

	/* ---- Renderização ----------------------------------------------------- */

	function panel() {
		return document.getElementById( 'tnc-ocr-item' );
	}

	function statusLabel( f ) {
		switch ( f.status ) {
			case 'queued':
				return f.position > 1 ? fmt( t.queuedPos, f.position ) : t.queued;
			case 'processing':
				if ( f.started_at ) {
					const minutes = Math.max( 1, Math.round( ( Date.now() / 1000 - f.started_at ) / 60 ) );
					return fmt( t.processingFor, minutes );
				}
				return t.processing;
			case 'done':
				return t.done;
			case 'empty':
				return t.empty;
			case 'failed':
				return t.failed;
			default:
				return t.none;
		}
	}

	function fileRow( f ) {
		let html = '<li class="tnc-ocr-file is-' + esc( f.status ) + '" data-id="' + esc( f.id ) + '">';
		html += '<div class="tnc-ocr-file__main">';
		html += '<span class="dashicons ' + ( f.is_image ? 'dashicons-format-image' : 'dashicons-pdf' ) + ' tnc-ocr-file__icon" aria-hidden="true"></span>';
		html += '<div class="tnc-ocr-file__info">';
		html += '<a class="tnc-ocr-file__name" href="' + esc( f.url ) + '" target="_blank" rel="noopener noreferrer">' + esc( f.name ) + '</a>';
		html += '<span class="tnc-ocr-file__role">' + esc( 'document' === f.role ? t.document : t.attachment ) + '</span>';
		html += '</div>';
		html += '<span class="tnc-ocr-badge is-' + esc( f.status ) + '">';
		if ( 'processing' === f.status ) {
			html += '<span class="tnc-ocr-spinner" aria-hidden="true"></span>';
		}
		html += esc( statusLabel( f ) ) + '</span>';
		html += '</div>';

		if ( 'done' === f.status || 'empty' === f.status ) {
			html += '<div class="tnc-ocr-file__meta">' + esc( fmt( t.stats, number( f.pages ), number( f.chars ) ) ) + '</div>';
			if ( f.preview ) {
				html += '<p class="tnc-ocr-file__preview">' + esc( f.preview ) + ( f.chars > 400 ? '…' : '' ) + '</p>';
			}
		}
		if ( 'failed' === f.status && f.error ) {
			html += '<p class="tnc-ocr-file__error">' + esc( f.error.slice( 0, 300 ) ) + '</p>';
		}

		// Ações do arquivo.
		html += '<div class="tnc-ocr-file__actions">';
		if ( 'done' === f.status ) {
			html += '<button type="button" class="button is-small tnc-ocr-act" data-act="text" data-id="' + esc( f.id ) + '" aria-expanded="' + ( state.openText[ f.id ] ? 'true' : 'false' ) + '">' + esc( state.openText[ f.id ] ? t.hideText : t.showText ) + '</button>';
		}
		if ( 'done' === f.status || 'empty' === f.status ) {
			if ( state.confirming === f.id ) {
				html += '<span class="tnc-ocr-confirm">' + esc( t.confirmRedo ) + ' ';
				html += '<button type="button" class="button is-small is-primary tnc-ocr-act" data-act="redo-yes" data-id="' + esc( f.id ) + '">' + esc( t.confirmYes ) + '</button> ';
				html += '<button type="button" class="button is-small tnc-ocr-act" data-act="redo-no">' + esc( t.confirmNo ) + '</button></span>';
			} else {
				html += '<button type="button" class="button is-small tnc-ocr-act" data-act="redo" data-id="' + esc( f.id ) + '">' + esc( t.redo ) + '</button>';
			}
		}
		if ( 'failed' === f.status ) {
			html += '<button type="button" class="button is-small tnc-ocr-act" data-act="one" data-id="' + esc( f.id ) + '">' + esc( t.retry ) + '</button>';
		}
		html += '</div>';

		if ( state.openText[ f.id ] ) {
			const pages = state.texts[ f.id ];
			html += '<div class="tnc-ocr-text" tabindex="0">';
			if ( ! pages ) {
				html += '<span class="tnc-ocr-spinner" aria-hidden="true"></span>';
			} else if ( ! pages.length ) {
				html += '<p>' + esc( t.noText ) + '</p>';
			} else {
				pages.forEach( function ( p ) {
					html += '<h5>' + esc( fmt( t.page, p.page ) ) + '</h5><p>' + esc( p.text ) + '</p>';
				} );
			}
			html += '</div>';
		}

		return html + '</li>';
	}

	let renderRetries = 0;

	function render() {
		const box = panel();
		if ( ! state.data ) {
			return;
		}
		if ( ! box ) {
			// O Vue pode montar o formulário depois que os dados chegam.
			if ( renderRetries++ < 20 ) {
				setTimeout( render, 500 );
			}
			return;
		}
		renderRetries = 0;
		const files = state.data.files || [];
		const list = box.querySelector( '#tnc-ocr-item-files' );
		const actions = box.querySelector( '#tnc-ocr-item-actions' );
		const summary = box.querySelector( '#tnc-ocr-item-summary' );
		box.hidden = false;

		if ( ! files.length ) {
			list.innerHTML = '';
			summary.textContent = '';
			actions.innerHTML = '<p class="tnc-ocr-item__note">' + esc( t.noFiles ) + '</p>';
			return;
		}

		list.innerHTML = files.map( fileRow ).join( '' );

		const withText = files.filter( function ( f ) { return 'done' === f.status; } ).length;
		summary.textContent = fmt( t.summary, withText, files.length );

		const pending = files.filter( function ( f ) { return 'none' === f.status || 'failed' === f.status; } );
		const active = files.some( function ( f ) { return 'queued' === f.status || 'processing' === f.status; } );

		let html = '';
		if ( pending.length ) {
			html += '<button type="button" class="button is-primary tnc-ocr-act tnc-ocr-run" data-act="all"' + ( state.busy ? ' disabled' : '' ) + '>';
			html += '<span class="dashicons dashicons-search" aria-hidden="true"></span>' + esc( fmt( t.runCount, pending.length ) ) + '</button>';
		}
		if ( active || state.busy ) {
			html += '<p class="tnc-ocr-item__note is-working"><span class="tnc-ocr-spinner" aria-hidden="true"></span>' + esc( t.working ) + '</p>';
		} else if ( ! pending.length ) {
			// Processados todos (com texto ou página sem texto).
			html += '<p class="tnc-ocr-item__note is-ok"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>' + esc( t.allDone ) + '</p>';
		}
		if ( state.data.auto_process && pending.length ) {
			html += '<p class="tnc-ocr-item__note">' + esc( t.auto ) + '</p>';
		}
		if ( state.error ) {
			html += '<p class="tnc-ocr-item__note is-error" role="alert">' + esc( state.error ) + '</p>';
		}
		actions.innerHTML = html;
	}

	function renderError() {
		const box = panel();
		if ( ! box ) {
			return;
		}
		box.hidden = false;
		box.querySelector( '#tnc-ocr-item-actions' ).innerHTML = '<p class="tnc-ocr-item__note is-error">' + esc( t.error ) + '</p>';
	}

	/* ---- Eventos (delegados: o Vue recria o formulário) -------------------- */

	document.addEventListener( 'click', function ( e ) {
		const btn = e.target.closest ? e.target.closest( '.tnc-ocr-act' ) : null;
		if ( ! btn || ! panel() || ! panel().contains( btn ) ) {
			return;
		}
		e.preventDefault();
		const id = parseInt( btn.getAttribute( 'data-id' ), 10 ) || 0;
		switch ( btn.getAttribute( 'data-act' ) ) {
			case 'all':
				return run( [] );
			case 'one':
				return run( [ id ] );
			case 'text':
				return toggleText( id );
			case 'redo':
				state.confirming = id;
				return render();
			case 'redo-yes':
				delete state.texts[ id ];
				delete state.openText[ id ];
				return run( [ id ], true );
			case 'redo-no':
				state.confirming = 0;
				return render();
		}
	} );
}() );
