<?php
/**
 * JetEngine Query Builder ↔ Etch Loop bridge.
 *
 * Standalone — runs without JetSmartFilters. Wrapper class is `je-etch-loop
 * je-q-{id}`. The bridge intercepts the WP_*_Query that Etch's loop handler
 * instantiates and replaces its args with the JE query's args.
 *
 * Supported JE query types and their Etch loop preset counterparts:
 *
 *   JE "posts"  → Etch wp-query / main-query  (pre_get_posts)
 *   JE "users"  → Etch wp-users               (pre_user_query)
 *   JE "terms"  → Etch wp-terms               (pre_get_terms)
 *
 * If JE query type doesn't match the firing hook, the bridge stays silent
 * (waits for the matching hook to fire — or no-ops if Etch loop preset type
 * doesn't align with JE query type).
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
		add_filter( 'render_block', [ $this, 'on_render_block' ], 999, 2 );

		// Dispatch by query type. Each handler checks JE query_type against
		// its own expected type and skips if mismatched.
		add_action( 'pre_get_posts', [ $this, 'on_pre_get_posts' ], 40 );
		add_action( 'pre_user_query', [ $this, 'on_pre_user_query' ], 10 );
		add_action( 'pre_get_terms', [ $this, 'on_pre_get_terms' ], 10 );
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

	/* -------------------- DISPATCHERS -------------------- */

	public function on_pre_get_posts( \WP_Query $query ): void {
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}
		if ( $query->is_main_query() ) {
			return;
		}

		// Skip wp_template / wp_navigation sub-queries.
		$post_type = $query->get( 'post_type' );
		$pt_check  = is_array( $post_type ) ? reset( $post_type ) : $post_type;
		if ( is_string( $pt_check ) && str_starts_with( $pt_check, 'wp_' ) ) {
			return;
		}

		$je_query = $this->resolve_je_query( 'posts' );
		if ( ! $je_query ) {
			return;
		}

		$je_args = $this->extract_args( $je_query );
		if ( null === $je_args ) {
			return;
		}

		// WP_Query has set() — stays compatible with internal cleanup logic.
		foreach ( $je_args as $key => $value ) {
			$query->set( $key, $value );
		}
		$query->set( '_jqbeb_je_query_id', $je_query->id ?? '' );

		$this->stack->pop();
	}

	public function on_pre_user_query( \WP_User_Query $query ): void {
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}

		$je_query = $this->resolve_je_query( 'users' );
		if ( ! $je_query ) {
			return;
		}

		$je_args = $this->extract_args( $je_query );
		if ( null === $je_args ) {
			return;
		}

		// WP_User_Query has no set() — mutate query_vars directly.
		foreach ( $je_args as $key => $value ) {
			$query->query_vars[ $key ] = $value;
		}
		// Etch's WpUsersLoopHandler iterates over WP_User instances —
		// force fields=all defensively in case JE returns IDs.
		$query->query_vars['fields'] = 'all';

		$this->stack->pop();
	}

	public function on_pre_get_terms( \WP_Term_Query $query ): void {
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}

		$je_query = $this->resolve_je_query( 'terms' );
		if ( ! $je_query ) {
			return;
		}

		$je_args = $this->extract_args( $je_query );
		if ( null === $je_args ) {
			return;
		}

		foreach ( $je_args as $key => $value ) {
			$query->query_vars[ $key ] = $value;
		}
		// Etch's WpTermsLoopHandler iterates over WP_Term instances —
		// force fields=all defensively in case JE returns IDs.
		$query->query_vars['fields'] = 'all';

		$this->stack->pop();
	}

	/* -------------------- SHARED LOOKUP / ARGS -------------------- */

	/**
	 * Looks up the JE query at the top of the stack and returns it ONLY if
	 * its `query_type` matches the expected type. Returns null on mismatch
	 * (without popping — the matching hook handler will pop). Returns null
	 * also on missing manager / missing query / unsupported type — and pops
	 * in those terminal cases to avoid stuck state.
	 */
	private function resolve_je_query( string $expected_type ) {
		$je_query_id = $this->stack->current();
		if ( ! $je_query_id ) {
			return null;
		}

		if ( ! class_exists( '\Jet_Engine\Query_Builder\Manager' ) ) {
			$this->debug( 'JetEngine Query_Builder Manager not available' );
			$this->stack->pop();
			return null;
		}

		$je_query = \Jet_Engine\Query_Builder\Manager::instance()->get_query_by_id( $je_query_id );
		if ( ! $je_query ) {
			$this->debug( 'JE query not found: ' . $je_query_id );
			$this->stack->pop();
			return null;
		}

		$query_type = property_exists( $je_query, 'query_type' ) ? $je_query->query_type : '';

		// Mismatch — silently skip without popping. The matching hook will
		// fire later (or won't, if the Etch preset type doesn't align).
		if ( $query_type !== $expected_type ) {
			return null;
		}

		return $je_query;
	}

	/**
	 * Inject pagination from URL into the JE query, then return its args.
	 * Returns null if the args are empty (caller pops in that case).
	 */
	private function extract_args( $je_query ): ?array {
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
			return null;
		}

		return $je_args;
	}

	/* -------------------- SAFETY-NET POP -------------------- */

	public function on_render_block( $block_content, $block ) {
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
		// Pop if a hook didn't claim it (e.g. preset type / JE type mismatch).
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
