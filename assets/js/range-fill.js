/**
 * JQBEB Range filter pending-state resolver.
 *
 * JSF 3.8.0.1+ ships a new async dynamic-range pattern: the PHP template
 * renders empty `value=""` on the editable text inputs (.jet-range__inputs__min
 * and .jet-range__inputs__max) and emits `data-dynamic-range-pending="1"` on
 * the wrapper. JSF's range constructor then calls
 * `clearPendingDynamicRangeDisplay()` which explicitly empties the editable
 * inputs and waits for `JetSmartFilterSettings.jetFiltersDynamicRange` to
 * populate them via `updateRangeBounds()` on the `jet-smart-filters/inited`
 * event. Crocoblock-native providers register themselves into that
 * localized structure server-side, but our `etch-loop` provider does not —
 * so on a JE-CMT-backed loop bridged through this plugin every range
 * filter stays empty until the user drags a thumb.
 *
 * Resolution: after JSF inits each filter, walk every filter group, find
 * each range filter with `dynamicRangePending`, and call `updateRangeBounds`
 * directly using the slider input's `min` / `max` attrs (which our PHP-side
 * v1.0.2 hook already populated correctly via the CMT lookup). JSF's own
 * `updateRangeBounds` then propagates min/max into the editable inputs
 * with the configured thousands-separator formatting.
 *
 * Backwards-compatible with JSF 3.7.x where filters have no
 * `dynamicRangePending` and the editable inputs simply weren't seeded by
 * init — there we dispatch a no-op `input` event on the slider input,
 * which makes JSF's own slider-input handler push the formatted value
 * into the editable input via `valuesUpdated('min'/'max')`.
 *
 * Triggers (cheapest first; per-filter `_jqbebRangeResolved` flag makes
 * the resolver idempotent so any of these can fire any number of times):
 *
 *   1. Immediate try on script load — covers the case where JSF inited
 *      before our script loaded.
 *   2. `jet-smart-filters/inited` event — primary, fires once after JSF
 *      has wired up every filter group on the page.
 *   3. MutationObserver on `data-jet-inited` attribute — catches
 *      AJAX-injected filter blocks (popups, lazy-loaded sections) and
 *      acts as a belt-and-suspenders for the inited event.
 */
( function () {
	'use strict';

	function fireInputEvent( el ) {
		if ( ! el ) {
			return;
		}
		var ev;
		try {
			ev = new Event( 'input', { bubbles: true } );
		} catch ( e ) {
			ev = document.createEvent( 'Event' );
			ev.initEvent( 'input', true, false );
		}
		el.dispatchEvent( ev );
	}

	function resolveRangeFilter( f ) {
		if ( ! f || f.name !== 'range' || f._jqbebRangeResolved ) {
			return false;
		}

		// JSF 3.8.0.1+ — call the public updateRangeBounds() directly.
		if ( f.dynamicRangePending && typeof f.updateRangeBounds === 'function' ) {
			var min = NaN, max = NaN;
			if ( f.$sliderInputMin && f.$sliderInputMin.attr ) {
				min = parseFloat( f.$sliderInputMin.attr( 'min' ) );
				max = parseFloat( f.$sliderInputMax.attr( 'max' ) );
			}
			if ( isNaN( min ) || isNaN( max ) ) {
				min = parseFloat( f.minConstraint );
				max = parseFloat( f.maxConstraint );
			}
			if ( isNaN( min ) || isNaN( max ) ) {
				return false;
			}
			f._jqbebRangeResolved = true;
			f.updateRangeBounds( { min: min, max: max } );
			return true;
		}

		// JSF 3.7.x — dispatch the slider input event so JSF's own handler
		// propagates value into editable inputs via valuesUpdated('min'/'max').
		if ( f.$sliderInputMin && f.$sliderInputMax ) {
			var inputMin = f.$rangeInputMin && f.$rangeInputMin[ 0 ];
			var inputMax = f.$rangeInputMax && f.$rangeInputMax[ 0 ];
			var needsFill = ( inputMin && inputMin.value === '' ) ||
			                ( inputMax && inputMax.value === '' );
			if ( ! needsFill ) {
				return false;
			}
			f._jqbebRangeResolved = true;
			fireInputEvent( f.$sliderInputMin[ 0 ] );
			fireInputEvent( f.$sliderInputMax[ 0 ] );
			return true;
		}

		return false;
	}

	function processAll() {
		if ( typeof window.JetSmartFilters === 'undefined' ||
		     ! window.JetSmartFilters.filterGroups ) {
			return;
		}
		var groups = window.JetSmartFilters.filterGroups;
		for ( var groupKey in groups ) {
			var group = groups[ groupKey ];
			if ( ! group || ! group.filters ) {
				continue;
			}
			for ( var filterKey in group.filters ) {
				resolveRangeFilter( group.filters[ filterKey ] );
			}
		}
	}

	function start() {
		// Try once immediately — handles the race where JSF inited before
		// our script attached its listener.
		processAll();

		// Primary trigger — JSF emits this once all filter groups are wired up.
		document.addEventListener( 'jet-smart-filters/inited', processAll );

		// Belt-and-suspenders: JSF sets `data-jet-inited="true"` on each
		// filter wrapper as soon as that filter's instance is wired up.
		// Watching this attribute catches AJAX-injected filter blocks
		// (popups, lazy-loaded sections) AND covers the rare case where
		// the inited event fired before us.
		if ( typeof MutationObserver !== 'undefined' ) {
			var observer = new MutationObserver( processAll );
			observer.observe( document.body, {
				subtree:         true,
				attributes:      true,
				attributeFilter: [ 'data-jet-inited' ],
			} );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
