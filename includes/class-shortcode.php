<?php
/**
 * [jsf_etch_count] shortcode — renders a span showing a JSF query prop
 * (found_posts / max_num_pages / page) for a given provider + query_id.
 *
 * Resolution runs in two passes so the initial paint shows the real number,
 * not a "0 → N" flash:
 *
 * 1. Synchronous resolve at shortcode-render time. Works whenever the
 *    matching loop block has ALREADY rendered earlier on the page (sections
 *    placed below the loop). JSF stores `found_posts` / `max_num_pages` /
 *    `page` via its `the_posts` priority 999 hook
 *    (jet-smart-filters/includes/query.php:31), so by the time we ask for
 *    `get_query_props()` after the loop's WP_Query has run, the values are
 *    there.
 *
 * 2. Late substitution via a page-wide output buffer started on
 *    `template_redirect` priority 1. When the shortcode renders BEFORE the
 *    loop (section above the loop, header, sidebar, hero, etc.), we emit a
 *    sentinel marker as inner text; on PHP shutdown the buffer callback
 *    re-asks `get_query_props()` — by then all loop blocks have rendered
 *    and the props are populated — and substitutes the real value before
 *    the response leaves PHP. The browser never sees the placeholder.
 *
 * The data-jsf-* attributes are kept on both paths so `assets/js/count.js`
 * can still update values on AJAX filter / pagination / sort changes.
 *
 * @package JQBEB
 */

namespace JQBEB;

defined( 'ABSPATH' ) || exit;

class Shortcode {

	/**
	 * Inner-text marker used when the shortcode is rendered before the loop.
	 * The output-buffer callback replaces this with the real value (or the
	 * configured placeholder if the loop still produced no props by flush).
	 *
	 * Underscore-fenced to keep it ASCII-safe and survive any intermediate
	 * filter / minifier without re-encoding.
	 */
	private const SENTINEL = '__JQBEB_COUNT_PENDING__';

	/** @var bool Page-wide buffer engaged this request? */
	private $buffer_started = false;

	public function __construct() {
		add_shortcode( 'jsf_etch_count', [ $this, 'render' ] );
		add_action( 'template_redirect', [ $this, 'maybe_start_buffer' ], 1 );
	}

	public function render( $atts ): string {
		$atts = shortcode_atts(
			[
				'provider'    => 'etch-loop',
				'query_id'    => 'default',
				'attr'        => 'found_posts',
				'placeholder' => '0',
			],
			$atts,
			'jsf_etch_count'
		);

		$value = $this->resolve_now( $atts['provider'], $atts['query_id'], $atts['attr'] );
		$inner = null !== $value ? $value : self::SENTINEL;

		return sprintf(
			'<span class="jsf-etch-count" data-jsf-provider="%s" data-jsf-query-id="%s" data-jsf-attr="%s" data-jsf-placeholder="%s">%s</span>',
			esc_attr( $atts['provider'] ),
			esc_attr( $atts['query_id'] ),
			esc_attr( $atts['attr'] ),
			esc_attr( $atts['placeholder'] ),
			esc_html( $inner )
		);
	}

	/**
	 * Synchronously read a JSF query prop. Returns the value as string, or
	 * null when JSF has nothing stored yet for this provider+query_id (i.e.
	 * the loop block hasn't rendered yet on this request).
	 */
	private function resolve_now( string $provider, string $query_id, string $attr ): ?string {
		if ( ! function_exists( 'jet_smart_filters' ) ) {
			return null;
		}
		$props = jet_smart_filters()->query->get_query_props( $provider, $query_id );
		if ( ! is_array( $props ) || ! array_key_exists( $attr, $props ) ) {
			return null;
		}
		$val = $props[ $attr ];
		if ( '' === $val || null === $val ) {
			return null;
		}
		return (string) $val;
	}

	/**
	 * Engage the page-wide output buffer used for late substitution. Skipped
	 * for non-front-end contexts where the buffer would be either pointless
	 * (admin, AJAX, REST, feeds) or harmful (response would no longer be
	 * streamable). Filterable via `jqbeb_count_late_substitution_enabled` for
	 * sites with custom output-streaming setups.
	 */
	public function maybe_start_buffer(): void {
		if ( $this->buffer_started ) {
			return;
		}
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		if ( function_exists( 'is_feed' ) && is_feed() ) {
			return;
		}
		if ( ! apply_filters( 'jqbeb_count_late_substitution_enabled', true ) ) {
			return;
		}
		$this->buffer_started = true;
		ob_start( [ $this, 'substitute_pending' ] );
	}

	/**
	 * Output-buffer callback. Runs once when the buffer flushes (PHP shutdown
	 * by default), at which point every loop on the page has already rendered
	 * and JSF has stored its props. Scans for jsf-etch-count spans whose
	 * inner text is still the sentinel and substitutes the now-known value.
	 *
	 * Cheap early-out via strpos when there's nothing to replace, so the
	 * regex never runs on pages that don't use the shortcode.
	 */
	public function substitute_pending( string $html ): string {
		if ( false === strpos( $html, self::SENTINEL ) ) {
			return $html;
		}

		$pattern = '#<span class="jsf-etch-count"\s+data-jsf-provider="([^"]*)"\s+data-jsf-query-id="([^"]*)"\s+data-jsf-attr="([^"]*)"\s+data-jsf-placeholder="([^"]*)">' . preg_quote( self::SENTINEL, '#' ) . '</span>#';

		return preg_replace_callback(
			$pattern,
			function ( $m ) {
				$provider    = wp_specialchars_decode( $m[1], ENT_QUOTES );
				$query_id    = wp_specialchars_decode( $m[2], ENT_QUOTES );
				$attr        = wp_specialchars_decode( $m[3], ENT_QUOTES );
				$placeholder = wp_specialchars_decode( $m[4], ENT_QUOTES );

				$value = $this->resolve_now( $provider, $query_id, $attr );
				$inner = null !== $value ? $value : $placeholder;

				return sprintf(
					'<span class="jsf-etch-count" data-jsf-provider="%s" data-jsf-query-id="%s" data-jsf-attr="%s" data-jsf-placeholder="%s">%s</span>',
					esc_attr( $provider ),
					esc_attr( $query_id ),
					esc_attr( $attr ),
					esc_attr( $placeholder ),
					esc_html( $inner )
				);
			},
			$html
		);
	}
}
