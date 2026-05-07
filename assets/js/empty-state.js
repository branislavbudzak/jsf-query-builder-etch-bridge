/**
 * JQBEB Empty results state toggle.
 *
 * When a JSF-driven Etch loop wrapper (.jsf-etch-loop) becomes empty —
 * either on initial render with 0 matching posts, or after an AJAX
 * filter pass yields 0 results (v1.0.4+ ensures the wrapper visibly
 * clears via the `<!--jqbeb:empty-results-->` sentinel) — this script:
 *
 *   1. Toggles `is-empty` on each `.jsf-etch-loop` wrapper, so site
 *      CSS can target the empty state without any JS-aware markup.
 *   2. Toggles `is-active` on every `.jsf-etch-empty-state` element
 *      paired with the wrapper, so a user-authored Etch placeholder
 *      (a heading, a paragraph, a whole card with image and link to a
 *      "request a vehicle alert" form, etc.) becomes visible.
 *
 * The user composes the empty-state inside Etch — this script never
 * generates content, only flips a class. That keeps the placeholder's
 * styling, layout and dynamic-data fully under the user's control.
 *
 * # Pairing
 *
 * - **Default** (single-loop pages or unique-empty-state-per-loop):
 *   `.jsf-etch-empty-state` is paired with the nearest enclosing
 *   ancestor that contains a `.jsf-etch-loop`. Empty-state elements
 *   nested inside a different loop wrapper are explicitly excluded.
 * - **Explicit** (multi-loop pages with separate empty states):
 *   `<div class="jsf-etch-empty-state" data-for-query-id="cars">`
 *   pairs with `<ul class="jsf-etch-loop jsf-etch-q-cars">`.
 *
 * # Default styling
 *
 * `.jsf-etch-empty-state:not(.is-active)` is hidden via an inline
 * `display:none !important` injected at script load. Sites that prefer
 * a different hiding mechanism (visibility, opacity, off-screen
 * positioning) can override with a higher-specificity rule on the
 * element's frontend stylesheet.
 *
 * # Re-entry
 *
 * MutationObserver watches every loop wrapper for childList changes,
 * plus the body for new loop wrappers added via AJAX (popups,
 * lazy-loaded sections). Toggle calls are idempotent.
 */
( function () {
	'use strict';

	var LOOP_SELECTOR        = '.jsf-etch-loop';
	var EMPTY_STATE_SELECTOR = '.jsf-etch-empty-state';
	var WRAPPER_EMPTY_CLASS  = 'is-empty';
	var EMPTY_VISIBLE_CLASS  = 'is-active';
	var STYLE_TAG_ID         = 'jqbeb-empty-state-style';

	function injectDefaultStyles() {
		if ( document.getElementById( STYLE_TAG_ID ) ) {
			return;
		}
		var style = document.createElement( 'style' );
		style.id = STYLE_TAG_ID;
		// Default-hide empty-state element until our toggle reveals it.
		// `!important` defends against Etch's own `display: flex` / `display: grid`
		// inline styles that would otherwise win over a plain rule.
		style.textContent = EMPTY_STATE_SELECTOR + ':not(.' + EMPTY_VISIBLE_CLASS + '){display:none !important}';
		document.head.appendChild( style );
	}

	function loopHasContent( loop ) {
		// HTML comments don't count as content (the v1.0.4 sentinel is one),
		// nor does whitespace-only text — both are normal in a freshly cleared
		// wrapper. Any element OR meaningful text means the loop has results.
		var children = loop.childNodes;
		for ( var i = 0; i < children.length; i++ ) {
			var child = children[ i ];
			if ( child.nodeType === 1 /* ELEMENT_NODE */ ) {
				return true;
			}
			if ( child.nodeType === 3 /* TEXT_NODE */ && child.textContent.trim() !== '' ) {
				return true;
			}
		}
		return false;
	}

	function getLoopQueryId( loop ) {
		var classes = ( loop.className || '' ).split( /\s+/ );
		for ( var i = 0; i < classes.length; i++ ) {
			if ( classes[ i ].indexOf( 'jsf-etch-q-' ) === 0 ) {
				return classes[ i ].slice( 'jsf-etch-q-'.length );
			}
		}
		return 'default';
	}

	function findEmptyStatesForLoop( loop ) {
		var qid = getLoopQueryId( loop );

		// 1. Explicit pairing wins. Multi-loop pages MUST use this.
		var explicit = document.querySelectorAll(
			EMPTY_STATE_SELECTOR + '[data-for-query-id="' + qid + '"]'
		);
		if ( explicit.length > 0 ) {
			return Array.prototype.slice.call( explicit );
		}

		// 2. Implicit: walk up from the loop, find empty-state elements
		//    in the same subtree that aren't trapped inside a different
		//    loop wrapper.
		var ancestor = loop.parentElement;
		while ( ancestor ) {
			var found = ancestor.querySelectorAll(
				EMPTY_STATE_SELECTOR + ':not([data-for-query-id])'
			);
			if ( found.length > 0 ) {
				var filtered = [];
				for ( var i = 0; i < found.length; i++ ) {
					var el        = found[ i ];
					// Skip if this element lives inside ANY .jsf-etch-loop —
					// that'd be either the same loop's children (would be
					// wiped on next AJAX replace) or a sibling loop's.
					var ownerLoop = el.closest( LOOP_SELECTOR );
					if ( ! ownerLoop ) {
						filtered.push( el );
					}
				}
				if ( filtered.length > 0 ) {
					return filtered;
				}
			}
			ancestor = ancestor.parentElement;
		}
		return [];
	}

	function syncLoop( loop ) {
		var hasContent = loopHasContent( loop );
		loop.classList.toggle( WRAPPER_EMPTY_CLASS, ! hasContent );
		var states = findEmptyStatesForLoop( loop );
		for ( var i = 0; i < states.length; i++ ) {
			states[ i ].classList.toggle( EMPTY_VISIBLE_CLASS, ! hasContent );
		}
	}

	function syncAllLoops() {
		var loops = document.querySelectorAll( LOOP_SELECTOR );
		for ( var i = 0; i < loops.length; i++ ) {
			syncLoop( loops[ i ] );
		}
	}

	function start() {
		injectDefaultStyles();
		syncAllLoops();

		if ( typeof MutationObserver === 'undefined' ) {
			return;
		}

		// Per-loop observer: catches every AJAX wrapper-inner replace.
		var loopObserver = new MutationObserver( function ( mutations ) {
			var seen = new Set();
			for ( var i = 0; i < mutations.length; i++ ) {
				var loop = mutations[ i ].target;
				if ( loop && loop.matches && loop.matches( LOOP_SELECTOR ) && ! seen.has( loop ) ) {
					seen.add( loop );
					syncLoop( loop );
				}
			}
		} );
		var existing = document.querySelectorAll( LOOP_SELECTOR );
		for ( var i = 0; i < existing.length; i++ ) {
			loopObserver.observe( existing[ i ], { childList: true } );
		}

		// Body observer: catches AJAX-injected NEW loop wrappers (popups,
		// lazy-loaded sections, dynamic listings).
		var bodyObserver = new MutationObserver( function ( mutations ) {
			for ( var i = 0; i < mutations.length; i++ ) {
				var added = mutations[ i ].addedNodes;
				for ( var j = 0; j < added.length; j++ ) {
					var node = added[ j ];
					if ( ! node || node.nodeType !== 1 ) {
						continue;
					}
					var newLoops = [];
					if ( node.matches && node.matches( LOOP_SELECTOR ) ) {
						newLoops.push( node );
					}
					if ( node.querySelectorAll ) {
						var inner = node.querySelectorAll( LOOP_SELECTOR );
						for ( var k = 0; k < inner.length; k++ ) {
							newLoops.push( inner[ k ] );
						}
					}
					for ( var l = 0; l < newLoops.length; l++ ) {
						loopObserver.observe( newLoops[ l ], { childList: true } );
						syncLoop( newLoops[ l ] );
					}
				}
			}
		} );
		bodyObserver.observe( document.body, { childList: true, subtree: true } );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
