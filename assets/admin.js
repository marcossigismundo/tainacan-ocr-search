/* global TainacanOCRSearch, wp */
( function () {
	'use strict';

	const cfg = window.TainacanOCRSearch || {};
	const i18n = cfg.i18n || {};
	const ns = '/' + cfg.restNamespace;

	function api( path, options = {} ) {
		return wp.apiFetch( Object.assign( { path: ns + path }, options ) );
	}

	function el( id ) {
		return document.getElementById( id );
	}

	let queueIds = [];
	let queueIndex = 0;
	let stopRequested = false;

	document.addEventListener( 'DOMContentLoaded', function () {
		bootSettings();
		bootCheck();
		bootCollections();
		bootBatch();
	} );

	// ---------- Passo 1: dependências ----------
	function bootCheck() {
		const btn = el( 'tnc-ocr-check' );
		if ( ! btn ) return;
		btn.addEventListener( 'click', runCheck );
		runCheck(); // verifica automaticamente ao abrir
	}

	function runCheck() {
		const out = el( 'tnc-ocr-check-result' );
		out.innerHTML = '<p>' + escape( i18n.checking ) + '</p>';
		api( '/check' ).then( function ( data ) {
			out.innerHTML = renderCheck( data );
			renderCoreStatus( data.tainacan_pdf_index || {} );
		} ).catch( function ( err ) {
			out.innerHTML = '<p class="tnc-ocr-bad">' + escape( i18n.errorPrefix + ( err.message || err ) ) + '</p>';
		} );
	}

	function renderCheck( data ) {
		const rows = [];
		[ 'tesseract', 'ocrmypdf', 'pdftotext', 'unpaper' ].forEach( function ( k ) {
			const r = data[ k ] || {};
			const optional = k === 'pdftotext' || k === 'unpaper';
			const cls = r.ok ? 'tnc-ocr-good' : ( optional ? '' : 'tnc-ocr-bad' );
			const status = r.ok ? '✓ ' + i18n.installed : '✗ ' + i18n.missing;
			rows.push(
				'<li class="' + cls + '"><strong>' + k + '</strong>' + ( optional ? ' <small>(' + escape( i18n.optional ) + ')</small>' : '' ) + ': ' + escape( status ) +
				( r.version ? ' <code>' + escape( r.version ) + '</code>' : '' ) +
				( r.error ? '<br><small>' + escape( r.error ) + '</small>' : '' ) +
				'</li>'
			);
		} );
		let langs = '';
		if ( data.tesseract && data.tesseract.languages ) {
			langs = '<p class="tnc-ocr-help">' + escape( i18n.langsInstalled ) + ' <code>' +
				escape( data.tesseract.languages.join( ', ' ) ) + '</code></p>';
		}
		return '<ul class="tnc-ocr-deps">' + rows.join( '' ) + '</ul>' + langs;
	}

	// ---------- Passo 4: indexação de PDF do core ----------
	function renderCoreStatus( core ) {
		const box = el( 'tnc-ocr-core-status' );
		if ( ! box ) return;
		box.innerHTML = '';
		const p = document.createElement( 'p' );
		p.className = core.ok ? 'tnc-ocr-good' : 'tnc-ocr-bad';
		p.textContent = ( core.ok ? '✓ ' : '✗ ' ) + ( core.ok ? i18n.coreOn : i18n.coreOff ) + ' ';
		if ( ! core.ok ) {
			const btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'button button-secondary';
			btn.textContent = i18n.coreEnable;
			btn.addEventListener( 'click', function () {
				btn.disabled = true;
				api( '/core-pdf-index', { method: 'POST' } ).then( function ( res ) {
					renderCoreStatus( { ok: !! res.enabled } );
				} ).catch( function ( err ) {
					btn.disabled = false;
					p.appendChild( document.createTextNode( ' ' + i18n.errorPrefix + ( err.message || err ) ) );
				} );
			} );
			p.appendChild( btn );
		}
		box.appendChild( p );
	}

	// ---------- Passo 2: configurações ----------
	function bootSettings() {
		const form = el( 'tnc-ocr-settings' );
		if ( ! form ) return;

		api( '/settings' ).then( function ( opts ) {
			Object.keys( opts ).forEach( function ( k ) {
				const field = form.elements[ k ];
				if ( ! field ) return;
				if ( field.type === 'checkbox' ) {
					field.checked = !! Number( opts[ k ] );
				} else {
					field.value = opts[ k ] != null ? opts[ k ] : '';
				}
			} );
		} );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			const data = {};
			Array.prototype.forEach.call( form.elements, function ( f ) {
				if ( ! f.name ) return;
				data[ f.name ] = f.type === 'checkbox' ? ( f.checked ? 1 : 0 ) : f.value;
			} );
			api( '/settings', { method: 'POST', data: data } ).then( function () {
				const msg = el( 'tnc-ocr-settings-msg' );
				msg.textContent = i18n.savedSettings;
				msg.className = 'tnc-ocr-msg tnc-ocr-good';
				setTimeout( function () { msg.textContent = ''; }, 3000 );
			} ).catch( function ( err ) {
				const msg = el( 'tnc-ocr-settings-msg' );
				msg.textContent = i18n.errorPrefix + ( err.message || err );
				msg.className = 'tnc-ocr-msg tnc-ocr-bad';
			} );
		} );
	}

	// ---------- Passo 3: coleções e lote ----------
	function bootCollections() {
		api( '/collections' ).then( function ( cols ) {
			const sel = el( 'tnc-ocr-collection' );
			if ( ! sel ) return;
			cols.forEach( function ( c ) {
				const opt = document.createElement( 'option' );
				opt.value = c.ID;
				opt.textContent = c.post_title || ( '#' + c.ID );
				sel.appendChild( opt );
			} );
		} );
	}

	function bootBatch() {
		const btnQueue = el( 'tnc-ocr-queue' );
		const btnStart = el( 'tnc-ocr-start' );
		const btnStop  = el( 'tnc-ocr-stop' );
		if ( ! btnQueue ) return;

		btnQueue.addEventListener( 'click', function () {
			const params = new URLSearchParams();
			const cid = el( 'tnc-ocr-collection' ).value;
			if ( cid ) params.set( 'collection_id', cid );
			params.set( 'only_pending', el( 'tnc-ocr-only-pending' ).checked ? '1' : '0' );
			api( '/queue?' + params.toString() ).then( function ( res ) {
				queueIds = res.ids || [];
				queueIndex = 0;
				stopRequested = false;
				el( 'tnc-ocr-total' ).textContent = res.count;
				el( 'tnc-ocr-done' ).textContent = '0';
				el( 'tnc-ocr-progress-wrap' ).hidden = false;
				el( 'tnc-ocr-log' ).innerHTML = '';
				updateBar();
				btnStart.disabled = res.count === 0;
				if ( res.count === 0 ) {
					addLog( i18n.noPending, 'tnc-ocr-good' );
				}
			} );
		} );

		btnStart.addEventListener( 'click', function () {
			if ( ! queueIds.length ) return;
			if ( ! window.confirm( ( i18n.confirmRun || '' ).replace( '%d', queueIds.length ) ) ) return;
			stopRequested = false;
			btnStart.disabled = true;
			btnStop.disabled = false;
			// O OCR roda no servidor, em fila: a página só enfileira (em partes de
			// 500, para não estourar o tempo da requisição) e acompanha.
			// Sem "apenas não processados", o lote refaz também os prontos.
			const force = ! el( 'tnc-ocr-only-pending' ).checked;
			chunks( queueIds, 500 ).reduce( function ( chain, part ) {
				return chain.then( function () {
					return api( '/enqueue', { method: 'POST', data: { attachment_ids: part, force: force } } );
				} );
			}, Promise.resolve() ).then( function () {
				addLog( i18n.queuedAll || '', '' );
				pollProgress();
			} ).catch( function ( err ) {
				addLog( i18n.errorPrefix + ( err.message || err ), 'tnc-ocr-bad' );
				btnStart.disabled = false;
				btnStop.disabled = true;
			} );
		} );

		btnStop.addEventListener( 'click', function () {
			stopRequested = true;
			btnStop.disabled = true;
			// Tira da fila o que ainda não começou; o arquivo em andamento termina.
			let removed = 0;
			chunks( queueIds, 1000 ).reduce( function ( chain, part ) {
				return chain.then( function () {
					return api( '/dequeue', { method: 'POST', data: { attachment_ids: part } } ).then( function ( res ) {
						removed += res.removed || 0;
					} );
				} );
			}, Promise.resolve() ).then( function () {
				addLog( ( i18n.paused || '' ).replace( '%d', removed ), '' );
				btnStart.disabled = false;
			} );
		} );
	}

	const reported = {};

	function chunks( list, size ) {
		const out = [];
		for ( let i = 0; i < list.length; i += size ) {
			out.push( list.slice( i, i + size ) );
		}
		return out;
	}

	function pollProgress() {
		// Em partes de 1000 IDs (o servidor não responde mais que isso por vez).
		const map = {};
		chunks( queueIds, 1000 ).reduce( function ( chain, part ) {
			return chain.then( function () {
				return api( '/progress', { method: 'POST', data: { attachment_ids: part } } ).then( function ( res ) {
					Object.assign( map, res || {} );
				} );
			} );
		}, Promise.resolve() ).then( function () {
			let finished = 0;
			let active = 0;
			queueIds.forEach( function ( id ) {
				const s = map[ id ];
				// Sem resposta para o ID: não dá para dizer que terminou.
				if ( ! s || 'queued' === s.status || 'processing' === s.status ) {
					active++;
					return;
				}
				finished++;
				if ( reported[ id ] || 'none' === s.status ) {
					return;
				}
				reported[ id ] = true;
				if ( 'failed' === s.status ) {
					addLog( '#' + id + ' ✗ ' + ( s.error || 'erro' ), 'tnc-ocr-bad' );
				} else {
					const detail = ( i18n.doneShort || '%1$s / %2$s' )
						.replace( '%1$s', s.pages || 0 )
						.replace( '%2$s', s.chars || 0 );
					addLog( '#' + id + ' ✓ ' + i18n.done + ' (' + detail + ')', 'tnc-ocr-good' );
				}
			} );
			queueIndex = finished;
			el( 'tnc-ocr-done' ).textContent = String( finished );
			updateBar();
			if ( active && ! stopRequested ) {
				setTimeout( pollProgress, 5000 );
			} else {
				el( 'tnc-ocr-start' ).disabled = false;
				el( 'tnc-ocr-stop' ).disabled = true;
			}
		} ).catch( function () {
			setTimeout( pollProgress, 10000 );
		} );
	}

	function updateBar() {
		const total = queueIds.length || 1;
		const pct = Math.round( ( queueIndex / total ) * 100 );
		const bar = document.querySelector( '.tnc-ocr-bar' );
		if ( bar ) bar.style.width = pct + '%';
	}

	function addLog( text, cls ) {
		const ul = el( 'tnc-ocr-log' );
		if ( ! ul ) return;
		const li = document.createElement( 'li' );
		li.textContent = text;
		if ( cls ) li.className = cls;
		ul.insertBefore( li, ul.firstChild );
		while ( ul.children.length > 200 ) ul.removeChild( ul.lastChild );
	}

	function escape( s ) {
		return String( s == null ? '' : s )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#39;' );
	}
} )();
