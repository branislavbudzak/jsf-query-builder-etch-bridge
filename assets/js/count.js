/**
 * Live updater for [jsf_etch_count] shortcode spans.
 *
 * Reads window.JQBEBData on DOMReady to fill initial values, and subscribes
 * to JSF event bus 'ajaxFilters/updated' for AJAX-time updates.
 *
 * @package JQBEB
 */
( function () {
	'use strict';

	function applyToCounts( provider, queryId, props ) {
		if ( ! props || typeof props !== 'object' ) {
			return;
		}
		document.querySelectorAll( '.jsf-etch-count' ).forEach( function ( el ) {
			var p = el.dataset.jsfProvider || 'etch-loop';
			var q = el.dataset.jsfQueryId || 'default';
			var a = el.dataset.jsfAttr || 'found_posts';
			if ( p !== provider || q !== queryId ) {
				return;
			}
			if ( props[ a ] !== undefined && props[ a ] !== null && props[ a ] !== '' ) {
				el.textContent = String( props[ a ] );
			}
		} );
	}

	function applyAllInitial() {
		var data = window.JQBEBData || {};
		Object.keys( data ).forEach( function ( provider ) {
			var byQ = data[ provider ] || {};
			Object.keys( byQ ).forEach( function ( queryId ) {
				applyToCounts( provider, queryId, byQ[ queryId ] );
			} );
		} );
	}

	function bindJsfEvents() {
		if (
			! window.JetSmartFilters ||
			! window.JetSmartFilters.events ||
			typeof window.JetSmartFilters.events.subscribe !== 'function'
		) {
			return setTimeout( bindJsfEvents, 200 );
		}
		// Signature from JSF bricks.js: (provider, queryId, response, options)
		// response.pagination = { found_posts, max_num_pages, page }
		window.JetSmartFilters.events.subscribe(
			'ajaxFilters/updated',
			function ( provider, queryId, response /* , options */ ) {
				if ( response && response.pagination ) {
					applyToCounts( provider, queryId, response.pagination );
				}
			}
		);
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', applyAllInitial );
	} else {
		applyAllInitial();
	}

	// Fill again after JSF inits, in case our script ran before JSF was ready.
	document.addEventListener( 'jet-smart-filters/inited', applyAllInitial );

	bindJsfEvents();
} )();
