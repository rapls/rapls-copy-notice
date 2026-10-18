/**
 * Quiet Copy Notice: reports copies of post text to the site.
 *
 * Reads only the selection, never the clipboard, and never blocks a copy.
 * By default it does nothing visible. Two options the site owner can turn on
 * change that: a short message after a copy, and a source line appended to
 * the copied text.
 */
( function () {
	'use strict';

	var config = window.qcnoticeConfig;
	if ( ! config || ! config.endpoint || ! config.postId ) {
		return;
	}

	var TEXT_MAX = 500;
	var REPEAT_WINDOW_MS = 10000;
	var last = { text: '', time: 0 };

	/**
	 * Elements that count as the post body: the parent of the marker the
	 * server placed after the post content, if present; otherwise the first
	 * selector in the list that matches anything on the page.
	 *
	 * @return {Element[]}
	 */
	function contentAreas() {
		var marker = config.marker ? document.querySelector( '.' + config.marker ) : null;
		if ( marker && marker.parentElement ) {
			return [ marker.parentElement ];
		}

		var selectors = splitSelectors( String( config.selector || '' ) );
		for ( var i = 0; i < selectors.length; i++ ) {
			var selector = selectors[ i ].trim();
			if ( ! selector ) {
				continue;
			}
			var found;
			try {
				found = document.querySelectorAll( selector );
			} catch ( e ) {
				continue;
			}
			if ( found.length ) {
				return Array.prototype.slice.call( found );
			}
		}
		return [];
	}

	/**
	 * Splits a selector list on the commas between selectors, leaving commas
	 * inside :is(), :where(), :not() or attribute values alone.
	 *
	 * @param {string} list Selector list.
	 * @return {string[]}
	 */
	function splitSelectors( list ) {
		var parts = [];
		var depth = 0;
		var quote = '';
		var start = 0;
		for ( var i = 0; i < list.length; i++ ) {
			var ch = list.charAt( i );
			if ( quote ) {
				if ( ch === quote ) {
					quote = '';
				}
			} else if ( '"' === ch || "'" === ch ) {
				quote = ch;
			} else if ( '(' === ch || '[' === ch ) {
				depth++;
			} else if ( ( ')' === ch || ']' === ch ) && depth > 0 ) {
				depth--;
			} else if ( ',' === ch && 0 === depth ) {
				parts.push( list.slice( start, i ) );
				start = i + 1;
			}
		}
		parts.push( list.slice( start ) );
		return parts;
	}

	/**
	 * Whether every range of the selection lies inside one content area.
	 *
	 * @param {Selection} selection Current selection.
	 * @return {boolean}
	 */
	function insideContent( selection ) {
		var areas = contentAreas();
		if ( ! areas.length ) {
			return false;
		}
		for ( var r = 0; r < selection.rangeCount; r++ ) {
			var node = selection.getRangeAt( r ).commonAncestorContainer;
			var inside = false;
			for ( var a = 0; a < areas.length; a++ ) {
				if ( areas[ a ] === node || areas[ a ].contains( node ) ) {
					inside = true;
					break;
				}
			}
			if ( ! inside ) {
				return false;
			}
		}
		return true;
	}

	var BLOCKS = 'p,li,blockquote,pre,h1,h2,h3,h4,h5,h6,td,th,dt,dd,figcaption';

	/**
	 * Text of the block the selection starts in, whitespace-normalised, or an
	 * empty string when it does not start inside a recognisable block of the
	 * content area. Read synchronously, before the selection can change.
	 *
	 * @param {Selection} selection Current selection.
	 * @return {string}
	 */
	function paragraphText( selection ) {
		var node = selection.getRangeAt( 0 ).startContainer;
		var el = node && node.nodeType === 1 ? node : node && node.parentElement;
		var block = el && el.closest ? el.closest( BLOCKS ) : null;
		if ( ! block ) {
			return '';
		}
		var areas = contentAreas();
		for ( var a = 0; a < areas.length; a++ ) {
			if ( areas[ a ].contains( block ) ) {
				var text = block.textContent.replace( /\s+/g, ' ' ).trim();
				return text.normalize ? text.normalize( 'NFC' ) : text;
			}
		}
		return '';
	}

	/**
	 * SHA-1 of a paragraph's text, as 40 hex digits. It lets copies of the same
	 * paragraph be grouped without storing the paragraph itself. Resolves to an
	 * empty string where the browser offers no Web Crypto (non-HTTPS pages).
	 *
	 * @param {string} text Paragraph text.
	 * @return {Promise<string>}
	 */
	function paragraphHash( text ) {
		var subtle = window.crypto && window.crypto.subtle;
		if ( ! text || ! subtle || typeof window.TextEncoder !== 'function' ) {
			return Promise.resolve( '' );
		}
		return subtle.digest( 'SHA-1', new window.TextEncoder().encode( text ) ).then( function ( buffer ) {
			return Array.prototype.map.call( new Uint8Array( buffer ), function ( b ) {
				return ( b < 16 ? '0' : '' ) + b.toString( 16 );
			} ).join( '' );
		} ).catch( function () {
			return '';
		} );
	}

	/**
	 * Host part of the referring page, if any.
	 *
	 * @return {string}
	 */
	function referrerHost() {
		if ( ! document.referrer ) {
			return '';
		}
		try {
			return new URL( document.referrer ).host;
		} catch ( e ) {
			return '';
		}
	}

	/**
	 * Sends the event. Failure is silent.
	 *
	 * @param {URLSearchParams} body Form body.
	 */
	function send( body ) {
		if ( navigator.sendBeacon && navigator.sendBeacon( config.endpoint, body ) ) {
			return;
		}
		if ( window.fetch ) {
			window.fetch( config.endpoint, {
				method: 'POST',
				body: body,
				keepalive: true,
				credentials: 'omit',
			} ).catch( function () {} );
		}
	}

	/**
	 * Whether the copy comes from a form field or editable region, where the
	 * clipboard is left exactly as the browser made it.
	 *
	 * @return {boolean}
	 */
	function copyingFromField() {
		var el = document.activeElement;
		return !! el && ( el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.isContentEditable );
	}

	/**
	 * Puts the selection plus a source line on the clipboard. Returns false,
	 * leaving the browser's own copy untouched, when that is not possible.
	 *
	 * @param {ClipboardEvent} event     The copy event.
	 * @param {Selection}      selection Current selection.
	 * @return {boolean}
	 */
	function addAttribution( event, selection ) {
		var source = config.attribution;
		if ( ! source || ! source.url || ! event.clipboardData || copyingFromField() ) {
			return false;
		}

		var plain = selection.toString() + '\n\n' + source.label + ' ' + ( source.title ? source.title + '\n' : '' ) + source.url;

		// The HTML flavour keeps formatting when pasting into an editor. It is
		// built in a detached element and never inserted into the page.
		var holder = document.createElement( 'div' );
		for ( var r = 0; r < selection.rangeCount; r++ ) {
			holder.appendChild( selection.getRangeAt( r ).cloneContents() );
		}
		var line = document.createElement( 'p' );
		var link = document.createElement( 'a' );
		link.href = source.url;
		link.textContent = source.title || source.url;
		line.appendChild( document.createTextNode( source.label + ' ' ) );
		line.appendChild( link );
		holder.appendChild( line );

		event.clipboardData.setData( 'text/plain', plain );
		event.clipboardData.setData( 'text/html', holder.innerHTML );
		event.preventDefault();
		return true;
	}

	var toast = null;
	var toastTimer = 0;

	/**
	 * Shows the site owner's message for a few seconds. Text only.
	 */
	function showMessage() {
		if ( ! config.message ) {
			return;
		}
		if ( ! toast ) {
			toast = document.createElement( 'div' );
			toast.className = 'qcnotice-message';
			toast.setAttribute( 'role', 'status' );
			toast.setAttribute( 'aria-live', 'polite' );
			var css = {
				position: 'fixed',
				left: '50%',
				bottom: '24px',
				transform: 'translateX(-50%)',
				maxWidth: 'min(90vw, 480px)',
				padding: '12px 16px',
				borderRadius: '6px',
				background: '#1d2327',
				color: '#fff',
				font: '14px/1.5 sans-serif',
				boxShadow: '0 4px 16px rgba(0,0,0,.25)',
				zIndex: '2147483000',
				cursor: 'pointer',
			};
			for ( var key in css ) {
				toast.style[ key ] = css[ key ];
			}
			toast.addEventListener( 'click', function () {
				toast.hidden = true;
			} );
			document.body.appendChild( toast );
		}
		toast.textContent = config.message;
		toast.hidden = false;
		window.clearTimeout( toastTimer );
		toastTimer = window.setTimeout( function () {
			toast.hidden = true;
		}, 5000 );
	}

	document.addEventListener( 'copy', function ( event ) {
		var selection;
		var chars;
		try {
			selection = window.getSelection();
			if ( ! selection || ! selection.rangeCount ) {
				return;
			}

			// Count code points, as the server does.
			chars = Array.from( selection.toString().trim() );
			if ( chars.length < config.minLength || ! insideContent( selection ) ) {
				return;
			}
		} catch ( e ) {
			return;
		}

		// Each step is isolated: a failure in one must not affect the copy or
		// the others.
		try {
			addAttribution( event, selection );
		} catch ( e ) {}

		try {
			showMessage();
		} catch ( e ) {}

		try {
			var text = chars.slice( 0, TEXT_MAX ).join( '' );
			var now = Date.now();
			if ( text === last.text && now - last.time < REPEAT_WINDOW_MS ) {
				return;
			}
			last = { text: text, time: now };

			var body = new URLSearchParams();
			body.append( 'post_id', String( config.postId ) );
			body.append( 'text', text );
			body.append( 'length', String( chars.length ) );
			body.append( 'ref', referrerHost() );
			paragraphHash( paragraphText( selection ) ).then( function ( hash ) {
				if ( hash ) {
					body.append( 'para', hash );
				}
				send( body );
			} );

			// For site owners who want to forward copies to their own
			// analytics. The plugin itself contacts no outside service.
			if ( typeof window.CustomEvent === 'function' ) {
				document.dispatchEvent( new window.CustomEvent( 'qcnotice:copy', {
					detail: { postId: config.postId, length: chars.length },
				} ) );
			}
		} catch ( e ) {}
	} );
} )();
