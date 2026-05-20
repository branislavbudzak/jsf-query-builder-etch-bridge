/**
 * JQBEB pagination diagnostics consumer.
 *
 * Reads buffers emitted by includes/class-debug.php (PHP `Debug::log()`
 * call sites) and prints them to the browser console as collapsed groups.
 *
 * Two sources:
 *   1. Initial page load → window.JQBEBDebug (inline <script> in footer).
 *   2. JSF AJAX (filter / pagination / sort) → response._jqbeb_debug
 *      (added via the `jet-smart-filters/render/ajax/data` PHP filter).
 *
 * JSF runs on jQuery; we listen to jQuery `ajaxComplete` and parse the
 * raw responseText (since JSF AJAX returns JSON we can't read via
 * `xhr.responseJSON` reliably across versions). Non-JSF AJAX responses
 * either don't parse as JSON or lack `_jqbeb_debug` and are silently
 * skipped — see the try/catch.
 *
 * No build step. Vanilla ES5-ish for broadest compatibility.
 */
( function () {
	'use strict';

	function formatTimestamp( entries, index ) {
		if ( ! entries || ! entries[ index ] ) {
			return '';
		}
		var ts = entries[ index ].ts;
		if ( typeof ts !== 'number' ) {
			return '';
		}
		var firstTs = entries[ 0 ].ts;
		var delta   = ( ts - firstTs ) * 1000;
		return '+' + delta.toFixed( 1 ) + 'ms';
	}

	function dump( source, payload ) {
		if ( ! payload || ! payload.entries || ! payload.entries.length ) {
			return;
		}
		var entries = payload.entries;
		console.groupCollapsed(
			'%c[jqbeb-debug] ' + source + ' (' + entries.length + ' entries)',
			'color: #888; font-weight: normal;'
		);
		entries.forEach( function ( entry, i ) {
			console.log(
				'%c' + formatTimestamp( entries, i ).padStart( 9 ),
				'color: #aaa;',
				entry.label,
				entry.data
			);
		} );
		console.groupEnd();
	}

	// Initial page render.
	function dumpPageLoad() {
		if ( window.JQBEBDebug ) {
			dump( 'page-load', window.JQBEBDebug );
			window.JQBEBDebug = null;
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', dumpPageLoad );
	} else {
		dumpPageLoad();
	}

	// JSF AJAX responses.
	function attachAjaxListener( $ ) {
		$( document ).ajaxComplete( function ( event, xhr, settings ) {
			if ( ! xhr || ! xhr.responseText ) {
				return;
			}
			// Cheap pre-check: skip parse if the marker isn't in the body.
			if ( xhr.responseText.indexOf( '_jqbeb_debug' ) === -1 ) {
				return;
			}
			try {
				var parsed = JSON.parse( xhr.responseText );
				if ( parsed && parsed._jqbeb_debug ) {
					var label = 'ajax';
					if ( settings && settings.data && typeof settings.data === 'string' ) {
						// Surface what we're paginating to / filtering by so the
						// dev can correlate multiple AJAX calls in one session.
						var pagedMatch = settings.data.match( /(?:^|&)paged=(\d+)/ );
						var providerMatch = settings.data.match( /(?:^|&)provider=([^&]+)/ );
						if ( providerMatch ) {
							label += ' [' + decodeURIComponent( providerMatch[ 1 ] ) + ']';
						}
						if ( pagedMatch ) {
							label += ' paged=' + pagedMatch[ 1 ];
						}
					}
					dump( label, parsed._jqbeb_debug );
				}
			} catch ( e ) {
				// Not JSON or unparseable — ignore. JSF responses are JSON, so
				// this only fires for non-JSF AJAX that happens to mention the
				// marker substring, which is benign.
			}
		} );
	}

	if ( window.jQuery ) {
		attachAjaxListener( window.jQuery );
	} else {
		// jQuery may load later (footer script). Poll briefly.
		var tries = 0;
		var iv    = setInterval( function () {
			if ( window.jQuery ) {
				clearInterval( iv );
				attachAjaxListener( window.jQuery );
			} else if ( ++tries > 40 ) {
				clearInterval( iv );
				console.warn( '[jqbeb-debug] jQuery not found — AJAX logs will not surface.' );
			}
		}, 50 );
	}
} )();
