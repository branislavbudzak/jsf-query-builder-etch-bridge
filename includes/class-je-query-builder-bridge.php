<?php
/**
 * JetEngine Query Builder ↔ Etch Loop bridge.
 *
 * Standalone — runs without JetSmartFilters. Wrapper class is `je-etch-loop
 * je-q-{id}`. The bridge wholesale-replaces WP_Query args from the JE query.
 *
 * If JSF is also active and the wrapper carries `jsf-etch-loop` classes too,
 * priority order on `pre_get_posts` is:
 *
 *   p4  pre_render_block  → JE captures wrapper
 *   p4  pre_render_block  → JSF captures wrapper
 *   p40 pre_get_posts     → JE wholesale-replaces args
 *   p50 pre_get_posts     → JSF tags query for filter merge
 *   p60 pre_get_posts     → JSF merges filter args on top of JE base
 *
 * @package JQBEB
 */

namespace JQBEB;

defined( 'ABSPATH' ) || exit;

class JE_Query_Builder_Bridge {

	private State_Stack $stack;

	public function __construct() {
		$this->stack = new State_Stack();

		add_filter( 'pre_render_block', [ $this, 'on_pre_render_block' ], 4, 2 );
		add_action( 'pre_get_posts', [ $this, 'replace_query_args_with_je' ], 40 );
		add_filter( 'render_block', [ $this, 'on_render_block' ], 999, 2 );
	}

	public static function get_classes( array $block ): string {
		return (string) ( $block['attrs']['attributes']['class'] ?? '' );
	}

	public static function extract_query_id( string $classes ): ?string {
		if ( preg_match( '/\bje-q-([a-z0-9_\-]+)\b/i', $classes, $m ) ) {
			return $m[1];
		}
		return null;
	}

	public function on_pre_render_block( $pre, $block ) {
		if ( wp_doing_ajax() || is_admin() ) {
			return $pre;
		}
		if ( ( $block['blockName'] ?? '' ) !== 'etch/element' ) {
			return $pre;
		}
		$classes = self::get_classes( $block );
		if ( ! preg_match( '/\bje-etch-loop\b/i', $classes ) ) {
			return $pre;
		}
		$query_id = self::extract_query_id( $classes );
		if ( ! $query_id ) {
			return $pre;
		}
		$this->stack->push( $query_id );
		return $pre;
	}

	public function replace_query_args_with_je( \WP_Query $query ): void {
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}
		if ( $query->is_main_query() ) {
			return;
		}

		$je_query_id = $this->stack->current();
		if ( ! $je_query_id ) {
			return;
		}

		// Skip wp_template / wp_navigation sub-queries.
		$post_type = $query->get( 'post_type' );
		$pt_check  = is_array( $post_type ) ? reset( $post_type ) : $post_type;
		if ( is_string( $pt_check ) && str_starts_with( $pt_check, 'wp_' ) ) {
			return;
		}

		if ( ! class_exists( '\Jet_Engine\Query_Builder\Manager' ) ) {
			$this->debug( 'JetEngine Query_Builder Manager not available' );
			$this->stack->pop();
			return;
		}

		$manager  = \Jet_Engine\Query_Builder\Manager::instance();
		$je_query = $manager->get_query_by_id( $je_query_id );

		if ( ! $je_query ) {
			$this->debug( 'JE query not found: ' . $je_query_id );
			$this->stack->pop();
			return;
		}

		// Only "posts" type — Etch loop iterates WP_Post.
		$query_type = property_exists( $je_query, 'query_type' ) ? $je_query->query_type : '';
		if ( $query_type && $query_type !== 'posts' ) {
			$this->debug( 'JE query type "' . $query_type . '" unsupported (posts only)' );
			$this->stack->pop();
			return;
		}

		// Inject pagination from URL params (JSF or plain pagination).
		if ( method_exists( $je_query, 'set_filtered_prop' ) ) {
			foreach ( [ 'jet_paged', 'paged', 'pagenum' ] as $page_key ) {
				if ( ! empty( $_REQUEST[ $page_key ] ) ) {
					$je_query->set_filtered_prop( '_page', absint( $_REQUEST[ $page_key ] ) );
					break;
				}
			}
		}

		$je_args = $je_query->get_query_args();
		if ( ! is_array( $je_args ) || empty( $je_args ) ) {
			$this->stack->pop();
			return;
		}

		// Wholesale replace. JE args already passed through
		// 'jet-engine/query-builder/types/posts-query/args' filter.
		foreach ( $je_args as $key => $value ) {
			$query->set( $key, $value );
		}

		// Marker for debug / Query Monitor.
		$query->set( '_jqbeb_je_query_id', $je_query_id );

		$this->stack->pop();
	}

	public function on_render_block( $block_content, $block ) {
		// Safety-net pop: if pre_get_posts didn't fire (no inner WP_Query),
		// state may still hold this query_id. Drain matching wrappers.
		if ( wp_doing_ajax() || is_admin() ) {
			return $block_content;
		}
		if ( ( $block['blockName'] ?? '' ) !== 'etch/element' ) {
			return $block_content;
		}
		$classes = self::get_classes( $block );
		if ( ! preg_match( '/\bje-etch-loop\b/i', $classes ) ) {
			return $block_content;
		}
		if ( ! $this->stack->is_empty() ) {
			$this->stack->pop();
		}
		return $block_content;
	}

	private function debug( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[jqbeb] ' . $message );
		}
	}
}
