<?php
/**
 * [jsf_etch_count] shortcode — renders a span placeholder that JS fills with
 * the current query props (found_posts / max_num_pages / page).
 *
 * @package JQBEB
 */

namespace JQBEB;

defined( 'ABSPATH' ) || exit;

class Shortcode {

	public function __construct() {
		add_shortcode( 'jsf_etch_count', [ $this, 'render' ] );
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

		// Best-effort initial value (works only if shortcode renders AFTER the loop).
		$initial = $atts['placeholder'];
		if ( function_exists( 'jet_smart_filters' ) ) {
			$props = jet_smart_filters()->query->get_query_props( $atts['provider'], $atts['query_id'] );
			if ( is_array( $props ) && isset( $props[ $atts['attr'] ] ) && '' !== $props[ $atts['attr'] ] ) {
				$initial = (string) $props[ $atts['attr'] ];
			}
		}

		return sprintf(
			'<span class="jsf-etch-count" data-jsf-provider="%s" data-jsf-query-id="%s" data-jsf-attr="%s">%s</span>',
			esc_attr( $atts['provider'] ),
			esc_attr( $atts['query_id'] ),
			esc_attr( $atts['attr'] ),
			esc_html( $initial )
		);
	}
}
