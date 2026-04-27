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
 *   JE 'posts'        → Etch wp-query / main-query  (pre_get_posts)
 *   JE 'users'        → Etch wp-users               (pre_user_query)
 *   JE 'terms'        → Etch wp-terms               (pre_get_terms)
 *   JE Merged_Query   → same hook as the merge's base_query_type, BUT the
 *                       integration is different (see Merged section below)
 *
 * Merged_Query handling
 * ---------------------
 * Merged_Query is same-type only — its `query_type` reports its base type
 * ('posts' / 'users' / 'terms'). It exposes no single WP_*_Query for us to
 * intercept; instead, sub-queries each run their own underlying query and
 * results are concatenated. Calling `get_query_args()` on a Merged_Query
 * returns a meaningless array_merge of all sub-queries' args (used by JE
 * only as a cache hash) — passing it to a WP_Query would corrupt the loop.
 *
 * Strategy: pre-fetch the merged result via `$merged->get_items()`, extract
 * IDs, and feed them to the firing query as `post__in` / `include`. The
 * underlying WP_*_Query then only resolves those IDs in their original
 * order, and built-in pagination is disabled (JE has already paginated
 * internally via `_page` + `max_items_per_page`).
 *
 * If JSF is also active and the wrapper carries `jsf-etch-loop` classes,
 * priority order on `pre_get_posts` is:
 *
 *   p4  pre_render_block  → JE captures wrapper
 *   p4  pre_render_block  → JSF captures wrapper
 *   p40 pre_get_posts     → JE replaces args
 *   p50 pre_get_posts     → JSF tags query for filter merge
 *   p60 pre_get_posts     → JSF merges filter args on top of JE base
 *
 * Note: JSF + Merged_Query is not a supported combination — JSF expects a
 * standard SQL-backed WP_Query and merges meta_query/tax_query into it,
 * but Merged bypasses the SQL by predefining `post__in`.
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

		if ( $this->is_merged( $je_query ) ) {
			$this->apply_merged_to_posts( $query, $je_query );
		} else {
			$this->apply_regular_to_posts( $query, $je_query );
		}

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

		if ( $this->is_merged( $je_query ) ) {
			$this->apply_merged_to_users( $query, $je_query );
		} else {
			$this->apply_regular_to_users( $query, $je_query );
		}

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

		if ( $this->is_merged( $je_query ) ) {
			$this->apply_merged_to_terms( $query, $je_query );
		} else {
			$this->apply_regular_to_terms( $query, $je_query );
		}

		$this->stack->pop();
	}

	/* -------------------- REGULAR QUERY APPLICATION -------------------- */

	private function apply_regular_to_posts( \WP_Query $query, $je_query ): void {
		$args = $this->get_args_with_pagination( $je_query );
		if ( null === $args ) {
			return;
		}
		foreach ( $args as $key => $value ) {
			$query->set( $key, $value );
		}
		$query->set( '_jqbeb_je_query_id', $je_query->id ?? '' );
	}

	private function apply_regular_to_users( \WP_User_Query $query, $je_query ): void {
		$args = $this->get_args_with_pagination( $je_query );
		if ( null === $args ) {
			return;
		}
		foreach ( $args as $key => $value ) {
			$query->query_vars[ $key ] = $value;
		}
		$query->query_vars['fields'] = 'all';
	}

	private function apply_regular_to_terms( \WP_Term_Query $query, $je_query ): void {
		$args = $this->get_args_with_pagination( $je_query );
		if ( null === $args ) {
			return;
		}
		foreach ( $args as $key => $value ) {
			$query->query_vars[ $key ] = $value;
		}
		$query->query_vars['fields'] = 'all';
	}

	/* -------------------- MERGED QUERY APPLICATION -------------------- */

	private function apply_merged_to_posts( \WP_Query $query, $je_query ): void {
		$ids = $this->extract_merged_ids( $je_query );

		// Empty merged set: post 0 never matches → empty loop.
		if ( empty( $ids ) ) {
			$ids = [ 0 ];
		}

		// Reset everything that would otherwise re-paginate or override
		// our pre-fetched ID list.
		$query->set( 'post__in', $ids );
		$query->set( 'orderby', 'post__in' );
		$query->set( 'posts_per_page', -1 );
		$query->set( 'paged', 1 );
		$query->set( 'nopaging', true );
		$query->set( 'no_found_rows', true );
		$query->set( 'ignore_sticky_posts', true );
		// Allow any post type — JE merged set may span types.
		$query->set( 'post_type', 'any' );
		$query->set( 'post_status', 'any' );
		$query->set( '_jqbeb_je_query_id', $je_query->id ?? '' );
		$query->set( '_jqbeb_je_merged', true );
	}

	private function apply_merged_to_users( \WP_User_Query $query, $je_query ): void {
		$ids = $this->extract_merged_ids( $je_query );

		if ( empty( $ids ) ) {
			$ids = [ 0 ];
		}

		$query->query_vars['include'] = $ids;
		$query->query_vars['orderby'] = 'include';
		$query->query_vars['number']  = 0; // 0 = no limit, return all included.
		$query->query_vars['paged']   = 1;
		$query->query_vars['fields']  = 'all';
	}

	private function apply_merged_to_terms( \WP_Term_Query $query, $je_query ): void {
		$ids = $this->extract_merged_ids( $je_query );

		if ( empty( $ids ) ) {
			$ids = [ 0 ];
		}

		$query->query_vars['include']    = $ids;
		$query->query_vars['orderby']    = 'include';
		$query->query_vars['number']     = 0;
		$query->query_vars['offset']     = 0;
		$query->query_vars['hide_empty'] = false;
		$query->query_vars['fields']     = 'all';
	}

	/**
	 * Pre-fetch merged items and extract their IDs.
	 *
	 * - WP_Post / WP_User → ->ID
	 * - WP_Term → ->term_id
	 *
	 * Pagination is delegated to the merged query via set_filtered_prop('_page').
	 */
	private function extract_merged_ids( $je_query ): array {
		if ( method_exists( $je_query, 'set_filtered_prop' ) ) {
			foreach ( [ 'jet_paged', 'paged', 'pagenum' ] as $page_key ) {
				if ( ! empty( $_REQUEST[ $page_key ] ) ) {
					$je_query->set_filtered_prop( '_page', absint( $_REQUEST[ $page_key ] ) );
					break;
				}
			}
		}

		if ( ! method_exists( $je_query, 'get_items' ) ) {
			$this->debug( 'Merged_Query missing get_items()' );
			return [];
		}

		$items = $je_query->get_items();
		if ( ! is_array( $items ) ) {
			return [];
		}

		$ids = [];
		foreach ( $items as $item ) {
			if ( $item instanceof \WP_Post || $item instanceof \WP_User ) {
				$ids[] = (int) $item->ID;
			} elseif ( $item instanceof \WP_Term ) {
				$ids[] = (int) $item->term_id;
			} elseif ( is_object( $item ) && isset( $item->ID ) ) {
				$ids[] = (int) $item->ID;
			} elseif ( is_object( $item ) && isset( $item->term_id ) ) {
				$ids[] = (int) $item->term_id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/* -------------------- SHARED LOOKUP / ARGS -------------------- */

	/**
	 * Looks up the JE query at the top of the stack and returns it ONLY if
	 * its `query_type` matches the expected type. Returns null on mismatch
	 * (without popping — the matching hook handler will pop). Returns null
	 * also on missing manager / missing query — and pops in those terminal
	 * cases to avoid stuck state.
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

	private function is_merged( $je_query ): bool {
		return $je_query instanceof \Jet_Engine\Query_Builder\Queries\Merged_Query;
	}

	/**
	 * Inject pagination from URL into the JE query, then return its args.
	 * Returns null if args are empty (caller pops in that case).
	 */
	private function get_args_with_pagination( $je_query ): ?array {
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
