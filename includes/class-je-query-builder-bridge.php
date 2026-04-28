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
 *   JE Merged_Query   → same hook as the merge's base_query_type
 *   JE 'sql'          → routed by cast_object_to or je-as-{type} hint
 *                       (pre-fetched, IDs fed via post__in / include)
 *   JE Data_Stores_Query (slug 'data-stores-query') → routed by the
 *                       store's underlying type (Posts or Users). Pre-fetched
 *                       via get_items(), IDs fed via post__in / include.
 *
 * Wrapper class hints
 * -------------------
 *   je-as-{posts|users|terms}  override target type (mainly for SQL)
 *   je-jsf-stack               opt-in JSF compatibility mode for Merged/SQL
 *                              (fetch all JE items, let WP_Query / JSF
 *                              natively paginate + filter the post__in subset)
 *
 * Merged + SQL handling
 * ---------------------
 * Both Merged_Query and SQL_Query bypass our hook-based wholesale-replace
 * approach because they don't expose a single WP_*_Query for us to mutate.
 * Strategy: pre-fetch via `$je_query->get_items()`, extract IDs, and feed
 * them to the firing Etch-instantiated query as `post__in` / `include`.
 *
 * Default mode (no je-jsf-stack):
 *   - JE paginates internally (set_filtered_prop _page from URL)
 *   - get_items() returns the current JE page only
 *   - WP_Query pagination is force-disabled (posts_per_page=-1, nopaging,
 *     no_found_rows) — we already have the right slice
 *   - JSF / counts shortcode are NOT supported for Merged/SQL in this mode
 *
 * je-jsf-stack mode:
 *   - JE pagination is overridden (max_items_per_page / limit_per_page /
 *     limit set to 0, _page=1) → get_items() returns ALL items
 *   - WP_Query pagination flags are NOT set → preset / JSF can paginate
 *   - JSF filter merging works (meta_query / tax_query intersect with
 *     post__in subset). Found_posts is computed correctly.
 *   - [jsf_etch_count] shortcode shows real counts of the filtered subset.
 *   - Cost: full JE result set is fetched on every render.
 *
 * If JSF is active and the wrapper carries `jsf-etch-loop` classes,
 * priority order on `pre_get_posts` is:
 *
 *   p4  pre_render_block  → JE captures je-etch-loop wrapper
 *   p4  pre_render_block  → JSF captures jsf-etch-loop wrapper
 *   p40 pre_get_posts     → JE replaces args
 *   p50 pre_get_posts     → JSF tags query for filter merge
 *   p60 pre_get_posts     → JSF merges filter args on top of JE base
 *
 * @package JQBEB
 */

namespace JQBEB;

defined( 'ABSPATH' ) || exit;

class JE_Query_Builder_Bridge {

	private State_Stack $stack;

	/**
	 * Re-entrancy guard. Set to true while we're calling JE methods that
	 * internally instantiate WP_Query / WP_User_Query / WP_Term_Query
	 * (Merged sub-queries, Data Store inner queries). Without this guard,
	 * those inner queries would re-fire pre_get_posts / pre_user_query /
	 * pre_get_terms and our handler would recurse on the same JE query.
	 */
	private bool $in_extraction = false;

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

	public static function extract_as_hint( string $classes ): ?string {
		if ( preg_match( '/\bje-as-(posts|users|terms)\b/i', $classes, $m ) ) {
			return strtolower( $m[1] );
		}
		return null;
	}

	public static function has_jsf_stack_hint( string $classes ): bool {
		return (bool) preg_match( '/\bje-jsf-stack\b/i', $classes );
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

		// Encode optional hints into the stack value.
		// Format: {id}[|as={type}][|stack=1]
		$parts   = [ $query_id ];
		$as_hint = self::extract_as_hint( $classes );
		if ( $as_hint ) {
			$parts[] = 'as=' . $as_hint;
		}
		if ( self::has_jsf_stack_hint( $classes ) ) {
			$parts[] = 'stack=1';
		}
		$this->stack->push( implode( '|', $parts ) );
		return $pre;
	}

	/* -------------------- DISPATCHERS -------------------- */

	public function on_pre_get_posts( \WP_Query $query ): void {
		if ( $this->in_extraction ) {
			return; // Recursive call from inside JE's internal query — let it run unmodified.
		}
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

		$resolved = $this->resolve_je_query( 'posts' );
		if ( ! $resolved ) {
			return;
		}
		[ $je_query, $hints ] = $resolved;

		if ( $this->is_merged( $je_query ) ) {
			$this->apply_ids_to_posts( $query, $je_query, 'merged', $hints['jsf_stack'] );
		} elseif ( $this->is_sql( $je_query ) ) {
			$this->apply_ids_to_posts( $query, $je_query, 'sql', $hints['jsf_stack'] );
		} elseif ( $this->is_data_store( $je_query ) ) {
			$this->apply_ids_to_posts( $query, $je_query, 'data_store', $hints['jsf_stack'] );
		} else {
			$this->apply_regular_to_posts( $query, $je_query );
		}

		$this->stack->pop();
	}

	public function on_pre_user_query( \WP_User_Query $query ): void {
		if ( $this->in_extraction ) {
			return;
		}
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}

		$resolved = $this->resolve_je_query( 'users' );
		if ( ! $resolved ) {
			return;
		}
		[ $je_query, $hints ] = $resolved;

		if ( $this->is_merged( $je_query ) || $this->is_sql( $je_query ) || $this->is_data_store( $je_query ) ) {
			$this->apply_ids_to_users( $query, $je_query, $hints['jsf_stack'] );
		} else {
			$this->apply_regular_to_users( $query, $je_query );
		}

		$this->stack->pop();
	}

	public function on_pre_get_terms( \WP_Term_Query $query ): void {
		if ( $this->in_extraction ) {
			return;
		}
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}

		$resolved = $this->resolve_je_query( 'terms' );
		if ( ! $resolved ) {
			return;
		}
		[ $je_query, $hints ] = $resolved;

		// Data Stores are Posts or Users only — never Terms.
		if ( $this->is_merged( $je_query ) || $this->is_sql( $je_query ) ) {
			$this->apply_ids_to_terms( $query, $je_query, $hints['jsf_stack'] );
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

	/* -------------------- ID-FED QUERY APPLICATION (Merged + SQL) -------------------- */

	private function apply_ids_to_posts( \WP_Query $query, $je_query, string $marker, bool $jsf_stack ): void {
		$ids = $this->extract_ids_from_get_items( $je_query, $jsf_stack );

		// Empty result → post 0 never matches → empty loop. Better than
		// leaving the query unmodified (which would render Etch's preset).
		if ( empty( $ids ) ) {
			$ids = [ 0 ];
		}

		$query->set( 'post__in', $ids );
		$query->set( 'orderby', 'post__in' );
		$query->set( 'ignore_sticky_posts', true );
		$query->set( 'post_type', 'any' );
		$query->set( 'post_status', 'any' );
		$query->set( '_jqbeb_je_query_id', $je_query->id ?? '' );
		$query->set( '_jqbeb_je_' . $marker, true );
		if ( $jsf_stack ) {
			$query->set( '_jqbeb_jsf_stack', true );
		}

		if ( ! $jsf_stack ) {
			// Default mode: JE has already returned the current page slice,
			// so disable WP_Query pagination — we just want WP_Query to
			// hydrate those exact IDs.
			$query->set( 'posts_per_page', -1 );
			$query->set( 'paged', 1 );
			$query->set( 'nopaging', true );
			$query->set( 'no_found_rows', true );
		}
		// In jsf_stack mode we leave pagination flags alone — the loop's
		// preset posts_per_page (overridable by JSF) drives pagination
		// over the full filtered post__in subset, and found_posts is
		// computed correctly so [jsf_etch_count] works.
	}

	private function apply_ids_to_users( \WP_User_Query $query, $je_query, bool $jsf_stack ): void {
		$ids = $this->extract_ids_from_get_items( $je_query, $jsf_stack );

		if ( empty( $ids ) ) {
			$ids = [ 0 ];
		}

		$query->query_vars['include'] = $ids;
		$query->query_vars['orderby'] = 'include';
		$query->query_vars['fields']  = 'all';

		if ( ! $jsf_stack ) {
			$query->query_vars['number'] = 0;
			$query->query_vars['paged']  = 1;
		}
		// In jsf_stack mode, leave 'number' / 'paged' to the preset.
		// WP_User_Query will paginate the include subset natively
		// and 'count_total' (default true) gives total_users.
	}

	private function apply_ids_to_terms( \WP_Term_Query $query, $je_query, bool $jsf_stack ): void {
		$ids = $this->extract_ids_from_get_items( $je_query, $jsf_stack );

		if ( empty( $ids ) ) {
			$ids = [ 0 ];
		}

		$query->query_vars['include']    = $ids;
		$query->query_vars['orderby']    = 'include';
		$query->query_vars['hide_empty'] = false;
		$query->query_vars['fields']     = 'all';

		if ( ! $jsf_stack ) {
			$query->query_vars['number'] = 0;
			$query->query_vars['offset'] = 0;
		}
	}

	/**
	 * Pre-fetch JE query items and extract IDs.
	 *
	 * Default mode: respects URL pagination — JE returns the current page
	 * only. Used when jsf_stack=false.
	 *
	 * jsf_stack mode: overrides JE's per-page caps (max_items_per_page /
	 * limit_per_page / limit set to 0, _page=1) so get_items() returns
	 * the FULL filter set. WP_Query / JSF then paginates the subset.
	 *
	 * Handles:
	 * - WP_Post / WP_User    → ->ID
	 * - WP_Term              → ->term_id
	 * - stdClass (raw SQL)   → heuristic check for ID / id / post_id /
	 *                          user_id / term_id columns
	 */
	private function extract_ids_from_get_items( $je_query, bool $jsf_stack = false ): array {
		// Wrap the entire JE-call section in the recursion guard. JE's
		// internal sub-queries (Merged sub-queries, Data Store inner
		// queries, SQL execution) may instantiate WP_Query / WP_User_Query
		// / WP_Term_Query, which fire pre_get_posts etc. The guard tells
		// our dispatchers to skip those re-entries.
		$this->in_extraction = true;
		try {
			// Ensure final_query is populated (lazy-built by setup_query()).
			// get_query_args() triggers setup_query if final_query is null.
			if ( method_exists( $je_query, 'get_query_args' ) ) {
				$je_query->get_query_args();
			}

			if ( $jsf_stack ) {
				// Force "fetch all" — clear JE's per-page caps and reset to
				// page 1 so get_items() returns the entire filter set.
				if ( property_exists( $je_query, 'final_query' ) && is_array( $je_query->final_query ) ) {
					$je_query->final_query['max_items_per_page'] = 0;  // Merged: 0 = unlimited
					$je_query->final_query['limit_per_page']     = 0;  // SQL: 0 = unlimited
					$je_query->final_query['limit']              = 0;  // SQL: 0 = unlimited
					$je_query->final_query['max_items']          = -1; // Data Store: -1 = unlimited
					$je_query->final_query['_page']              = 1;
					$je_query->final_query['page']               = 1;  // Data Store also reads 'page'
					$je_query->final_query['paged']              = 1;  // Data Store + others read 'paged'
				}
				// Data Store caches its inner query in current_query — reset
				// so it rebuilds with our overrides applied.
				if ( method_exists( $je_query, 'reset_query' ) ) {
					$je_query->reset_query();
				}
			} elseif ( method_exists( $je_query, 'set_filtered_prop' ) ) {
				// Default: respect URL pagination — JE returns current page only.
				foreach ( [ 'jet_paged', 'paged', 'pagenum' ] as $page_key ) {
					if ( ! empty( $_REQUEST[ $page_key ] ) ) {
						$je_query->set_filtered_prop( '_page', absint( $_REQUEST[ $page_key ] ) );
						break;
					}
				}
			}

			if ( ! method_exists( $je_query, 'get_items' ) ) {
				$this->debug( 'JE query missing get_items()' );
				return [];
			}

			$items = $je_query->get_items();
		} finally {
			$this->in_extraction = false;
		}

		if ( ! is_array( $items ) ) {
			return [];
		}

		$ids = [];
		foreach ( $items as $item ) {
			if ( $item instanceof \WP_Post || $item instanceof \WP_User ) {
				$ids[] = (int) $item->ID;
			} elseif ( $item instanceof \WP_Term ) {
				$ids[] = (int) $item->term_id;
			} elseif ( is_object( $item ) ) {
				foreach ( [ 'ID', 'id', 'post_id', 'user_id', 'term_id' ] as $key ) {
					if ( isset( $item->$key ) && (int) $item->$key > 0 ) {
						$ids[] = (int) $item->$key;
						break;
					}
				}
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/* -------------------- SHARED LOOKUP / ARGS -------------------- */

	/**
	 * Resolves the JE query at the top of the stack and returns it ONLY
	 * if its target type matches `$expected_type`. Returns null on
	 * mismatch (without popping — the matching hook handler will pop).
	 * Returns null also on missing manager / missing query — and pops in
	 * those terminal cases to avoid stuck state.
	 *
	 * @return array{0: object, 1: array{as: ?string, jsf_stack: bool}}|null
	 */
	private function resolve_je_query( string $expected_type ): ?array {
		$state = $this->stack->current();
		if ( ! $state ) {
			return null;
		}

		[ $je_query_id, $hints ] = $this->parse_state( $state );

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

		$query_type = property_exists( $je_query, 'query_type' ) ? (string) $je_query->query_type : '';

		// SQL queries don't carry a posts/users/terms type natively —
		// infer from wrapper hint OR cast_object_to.
		if ( $query_type === 'sql' ) {
			$sql_target = $hints['as'] ?: $this->infer_sql_target_type( $je_query );
			if ( $sql_target !== $expected_type ) {
				return null;
			}
			return [ $je_query, $hints ];
		}

		// Data Store queries wrap an underlying Posts or Users sub-query.
		// Determine the effective type from the store's configuration
		// (cheap — no inner query materialisation required).
		if ( $query_type === 'data-stores-query' ) {
			$ds_target = $hints['as'] ?: $this->get_data_store_target_type( $je_query );
			if ( $ds_target !== $expected_type ) {
				return null;
			}
			return [ $je_query, $hints ];
		}

		// Regular and Merged queries carry their target type directly,
		// but a wrapper hint can still override (e.g. force Posts query
		// to be treated as Users — niche but supported).
		if ( $hints['as'] && $hints['as'] !== $expected_type ) {
			return null;
		}
		if ( ! $hints['as'] && $query_type !== $expected_type ) {
			return null;
		}

		return [ $je_query, $hints ];
	}

	/**
	 * @return array{0: string, 1: array{as: ?string, jsf_stack: bool}}
	 */
	private function parse_state( string $state ): array {
		$parts = explode( '|', $state );
		$id    = array_shift( $parts );
		$hints = [
			'as'        => null,
			'jsf_stack' => false,
		];
		foreach ( $parts as $part ) {
			if ( str_starts_with( $part, 'as=' ) ) {
				$hints['as'] = substr( $part, 3 ) ?: null;
			} elseif ( $part === 'stack=1' ) {
				$hints['jsf_stack'] = true;
			}
		}
		return [ (string) $id, $hints ];
	}

	private function infer_sql_target_type( $je_query ): string {
		$cast = '';
		if ( property_exists( $je_query, 'query' ) && is_array( $je_query->query ) ) {
			$cast = (string) ( $je_query->query['cast_object_to'] ?? '' );
		}
		$cast = ltrim( $cast, '\\' );
		if ( strcasecmp( $cast, 'WP_User' ) === 0 ) {
			return 'users';
		}
		if ( strcasecmp( $cast, 'WP_Term' ) === 0 ) {
			return 'terms';
		}
		// Default: posts (covers WP_Post, no cast, and any custom class
		// whose IDs the user wants to feed into a posts loop).
		return 'posts';
	}

	private function is_merged( $je_query ): bool {
		return $je_query instanceof \Jet_Engine\Query_Builder\Queries\Merged_Query;
	}

	private function is_sql( $je_query ): bool {
		return $je_query instanceof \Jet_Engine\Query_Builder\Queries\SQL_Query;
	}

	private function is_data_store( $je_query ): bool {
		return class_exists( '\Jet_Engine\Modules\Data_Stores\Query_Builder\Data_Stores_Query' )
			&& $je_query instanceof \Jet_Engine\Modules\Data_Stores\Query_Builder\Data_Stores_Query;
	}

	/**
	 * Determine whether a Data Store query targets posts or users.
	 *
	 * Cheap path: read the store's slug from final_query, look up the
	 * store via Module::stores->get_store(), call $store->is_user_store().
	 * This avoids triggering Data_Stores_Query::get_query_type() which
	 * materialises the inner WP_Query / WP_User_Query just to determine
	 * the type.
	 */
	private function get_data_store_target_type( $je_query ): string {
		$this->in_extraction = true;
		try {
			if ( method_exists( $je_query, 'get_query_args' ) ) {
				// Lazy-build final_query without firing inner queries.
				$je_query->get_query_args();
			}
		} finally {
			$this->in_extraction = false;
		}

		$store_slug = '';
		if ( property_exists( $je_query, 'final_query' ) && is_array( $je_query->final_query ) ) {
			$store_slug = (string) ( $je_query->final_query['store_slug'] ?? '' );
		}

		if ( ! $store_slug || ! class_exists( '\Jet_Engine\Modules\Data_Stores\Module' ) ) {
			return 'posts';
		}

		$module = \Jet_Engine\Modules\Data_Stores\Module::instance();
		if ( ! is_object( $module ) || ! property_exists( $module, 'stores' ) ) {
			return 'posts';
		}

		$store = $module->stores->get_store( $store_slug );
		if ( ! is_object( $store ) || ! method_exists( $store, 'is_user_store' ) ) {
			return 'posts';
		}

		return $store->is_user_store() ? 'users' : 'posts';
	}

	/**
	 * Inject pagination from URL into the JE query, then return its args.
	 * Returns null if args are empty (caller pops in that case).
	 */
	private function get_args_with_pagination( $je_query ): ?array {
		$this->in_extraction = true;
		try {
			if ( method_exists( $je_query, 'set_filtered_prop' ) ) {
				foreach ( [ 'jet_paged', 'paged', 'pagenum' ] as $page_key ) {
					if ( ! empty( $_REQUEST[ $page_key ] ) ) {
						$je_query->set_filtered_prop( '_page', absint( $_REQUEST[ $page_key ] ) );
						break;
					}
				}
			}

			$je_args = $je_query->get_query_args();
		} finally {
			$this->in_extraction = false;
		}

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
