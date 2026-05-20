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

	/**
	 * Stack of encoded `{qid}|as=...|stack=1` strings for currently-open
	 * je-etch-loop wrappers — pushed at wrapper open, popped at wrapper
	 * close. The actual State_Stack push (which the dispatchers consume)
	 * happens only when an `etch/loop` child block renders inside the
	 * wrapper, so dynamic-data sub-queries that fire BEFORE the loop
	 * block (e.g. partner CPT lookup) don't eat our state and pop it
	 * before the loop's own query gets a chance to read it.
	 *
	 * @var string[]
	 */
	private array $wrapper_data_open = [];

	public function __construct() {
		$this->stack = new State_Stack();

		add_filter( 'pre_render_block', [ $this, 'on_pre_render_block' ], 4, 2 );
		add_filter( 'render_block', [ $this, 'on_render_block' ], 999, 2 );

		// Dispatch by query type. Each handler checks JE query_type against
		// its own expected type and skips if mismatched.
		add_action( 'pre_get_posts', [ $this, 'on_pre_get_posts' ], 40 );
		add_action( 'pre_user_query', [ $this, 'on_pre_user_query' ], 10 );
		add_action( 'pre_get_terms', [ $this, 'on_pre_get_terms' ], 10 );

		// CMT redirect runs LATE (priority 70) so it sees the meta_query
		// AFTER JSF's filter merge at priority 60. If we split earlier (at
		// p40 next to apply_regular_to_posts), JSF-added meta_query clauses
		// referencing CMT fields would not be redirected and would search
		// wp_postmeta — which never has the values — yielding 0 rows for
		// any JSF filter on a CMT field. Running at p70 ensures the split
		// is over the FINAL meta_query (JE base + JSF filters merged).
		add_action( 'pre_get_posts', [ $this, 'apply_cmt_redirect_late' ], 70 );
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
		if ( ! JSF_Bridge::$in_ajax_render && ( wp_doing_ajax() || is_admin() ) ) {
			return $pre;
		}

		$bn = $block['blockName'] ?? '';

		// Wrapper open — remember the encoded data for this je-etch-loop
		// wrapper; the State_Stack push happens later when the etch/loop
		// child renders. This indirection avoids dynamic-data sub-queries
		// (e.g. a single partner-CPT lookup that fires DURING wrapper render
		// but BEFORE the loop block) consuming our State_Stack via
		// on_pre_get_posts and popping it before the loop's own query.
		if ( $bn === 'etch/element' ) {
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
			$this->wrapper_data_open[] = implode( '|', $parts );
			return $pre;
		}

		// Loop block inside an open je-etch-loop wrapper — push the
		// topmost wrapper data onto State_Stack. The next WP_*_Query that
		// fires (Etch's loop handler instantiates one) is the loop's own
		// query and the dispatcher will read + apply + pop.
		if ( $bn === 'etch/loop' && ! empty( $this->wrapper_data_open ) ) {
			$this->stack->push( end( $this->wrapper_data_open ) );
		}

		return $pre;
	}

	/* -------------------- DISPATCHERS -------------------- */

	public function on_pre_get_posts( \WP_Query $query ): void {
		if ( $this->in_extraction ) {
			return; // Recursive call from inside JE's internal query — let it run unmodified.
		}
		if ( ! JSF_Bridge::$in_ajax_render && ( is_admin() || wp_doing_ajax() ) ) {
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
		if ( ! JSF_Bridge::$in_ajax_render && ( is_admin() || wp_doing_ajax() ) ) {
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
		if ( ! JSF_Bridge::$in_ajax_render && ( is_admin() || wp_doing_ajax() ) ) {
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
		if ( Debug::pagination_enabled() ) {
			Debug::log( 'apply_regular_to_posts BEFORE', [
				'query_paged'        => $query->get( 'paged' ),
				'query_pp'           => $query->get( 'posts_per_page' ),
				'query_geo_query'    => $query->get( 'geo_query' ),
				'request_paged'      => $_REQUEST['paged'] ?? null,
				'request_jet_paged'  => $_REQUEST['jet_paged'] ?? null,
				'request_pagenum'    => $_REQUEST['pagenum'] ?? null,
				'request_top_geo'    => $_REQUEST['geo_query'] ?? null,
				'request_query_geo'  => $_REQUEST['query']['geo_query'] ?? null,
				'is_ajax'            => wp_doing_ajax(),
				'in_ajax_render'     => JSF_Bridge::$in_ajax_render,
			] );
		}
		$args = $this->get_args_with_pagination( $je_query );
		if ( null === $args ) {
			return;
		}
		if ( Debug::pagination_enabled() ) {
			Debug::log( 'apply_regular_to_posts JE_ARGS', [
				'paged'          => $args['paged'] ?? null,
				'posts_per_page' => $args['posts_per_page'] ?? null,
				'geo_query'      => $args['geo_query'] ?? null,
				'orderby'        => $args['orderby'] ?? null,
				'has_meta_query' => isset( $args['meta_query'] ),
				'has_tax_query'  => isset( $args['tax_query'] ),
			] );
		}
		foreach ( $args as $key => $value ) {
			$query->set( $key, $value );
		}
		$query->set( '_jqbeb_je_query_id', $je_query->id ?? '' );
		// CMT redirect deliberately deferred to apply_cmt_redirect_late
		// (pre_get_posts p70) so it sees JSF's filter merge from p60.
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

		// Capture Etch preset's post_type BEFORE we override to 'any'.
		// apply_cmt_redirect_late at p70 needs the actual CPT slug to
		// match a CMT storage by object_slug; with post_type='any' the
		// CMT redirect bails and JSF sort/filter on CMT fields silently
		// fails (orderby=meta_value_num + meta_key=<cmt-field> joins
		// wp_postmeta which has no CMT data → 0 rows).
		$original_post_type = $query->get( 'post_type' );

		$query->set( 'post__in', $ids );
		$query->set( 'orderby', 'post__in' );
		$query->set( 'ignore_sticky_posts', true );
		$query->set( 'post_type', 'any' );
		$query->set( 'post_status', 'any' );
		$query->set( '_jqbeb_je_query_id', $je_query->id ?? '' );
		$query->set( '_jqbeb_je_' . $marker, true );
		if ( $original_post_type ) {
			$query->set( '_jqbeb_je_original_post_type', $original_post_type );
		}
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

	/* -------------------- CMT (Custom Meta Tables) REDIRECT -------------------- */

	/**
	 * Late hook (pre_get_posts priority 70) that runs the CMT redirect on
	 * any query our bridge tagged with `_jqbeb_je_query_id`. Runs AFTER
	 * JSF's filter merge at priority 60 so the split sees the combined
	 * meta_query (JE base + JSF filters) and routes every CMT-stored
	 * clause into `custom_table_query`. Bails on:
	 *
	 *   - re-entrant pre_get_posts inside our own JE method calls
	 *   - admin / AJAX
	 *   - main query
	 *   - queries we did not mark
	 */
	public function apply_cmt_redirect_late( \WP_Query $query ): void {
		if ( $this->in_extraction ) {
			return;
		}
		if ( ! JSF_Bridge::$in_ajax_render && ( is_admin() || wp_doing_ajax() ) ) {
			return;
		}
		if ( $query->is_main_query() ) {
			return;
		}
		if ( ! $query->get( '_jqbeb_je_query_id' ) ) {
			return;
		}
		$this->apply_cmt_redirect( $query );
	}

	/**
	 * Detect CMT-stored fields in the applied JE args and replicate JE's
	 * pre_get_posts splitter inline.
	 *
	 * Why this is needed
	 * ------------------
	 * JetEngine's `Custom_Tables\Manager` registers a GLOBAL `posts_clauses`
	 * filter (priority 10) that emits a CMT JOIN/WHERE/ORDER if a query
	 * carries a `custom_table_query` query var. JE's own `pre_get_posts`
	 * handler (priority 10, one closure per CMT post type — see
	 * `jet-engine/.../post-types/custom-tables/query.php:354`) populates
	 * that var by splitting `meta_query` into postmeta-stored vs CMT-stored
	 * clauses.
	 *
	 * Our bridge sets the JE args at `pre_get_posts` priority 40 — strictly
	 * AFTER JE's splitter. So when the splitter ran, the query still carried
	 * Etch's preset args (no CMT meta_query). It found nothing to split, and
	 * `custom_table_query` stayed unset. The global `posts_clauses` filter
	 * therefore did not emit the CMT JOIN, and the resulting SQL queried
	 * `wp_postmeta` for fields that are NOT there — returning 0 results
	 * even though JE's own `get_items()` returned full data.
	 *
	 * What this method does
	 * ---------------------
	 * Mirrors `Jet_Engine\CPT\Custom_Tables\Query::pre_get_posts` (the
	 * closure body) and `exctract_meta_query_partials()`:
	 *
	 *   1. Looks up the CMT storage matching the applied `post_type` from
	 *      `Manager::$storages`. Bails if none.
	 *   2. Splits `meta_query` into `custom_query` (CMT clauses) + plain
	 *      `meta_query` (postmeta clauses).
	 *   3. Rewrites `orderby` so any `meta_value` / `meta_value_num` keys
	 *      pointing at CMT fields become a `RAND($t)` placeholder; the
	 *      global `posts_clauses` filter str_replaces the placeholder back
	 *      with `{cmt_table}.{column}` at SQL build time.
	 *   4. Sets `custom_table_query` query var; the global filter takes it
	 *      from there.
	 *
	 * Tightly coupled to JE internals — if JE changes its split logic /
	 * orderby trick, we need to re-sync. Public API surface we depend on:
	 *   - `\Jet_Engine\CPT\Custom_Tables\Manager::instance()`
	 *   - `Manager::$storages` (public array)
	 *   - `Manager::get_table_name( $object_slug )`
	 *   - `custom_table_query` query var contract (table / query / order)
	 */
	private function apply_cmt_redirect( \WP_Query $query ): void {
		if ( ! class_exists( '\Jet_Engine\CPT\Custom_Tables\Manager' ) ) {
			return;
		}
		$manager = \Jet_Engine\CPT\Custom_Tables\Manager::instance();
		if ( empty( $manager->storages ) ) {
			return;
		}

		// For SQL / Merged / Data Stores loops, apply_ids_to_posts overrides
		// post_type to 'any' (post__in determines the result set, post_type
		// would otherwise filter it back). The pre-override post_type is
		// stashed in _jqbeb_je_original_post_type — use it for CMT storage
		// matching, otherwise fall back to the live post_type query var
		// (which is correct for apply_regular_to_posts).
		$post_types = $query->get( '_jqbeb_je_original_post_type' );
		if ( ! $post_types ) {
			$post_types = $query->get( 'post_type' );
		}
		$post_types = (array) $post_types;

		$matching = null;
		foreach ( $manager->storages as $storage ) {
			if ( ( $storage['object_type'] ?? '' ) !== 'post' ) {
				continue;
			}
			if ( in_array( $storage['object_slug'] ?? '', $post_types, true ) ) {
				$matching = $storage;
				break;
			}
		}
		if ( ! $matching ) {
			return;
		}

		$cmt_fields = $matching['fields'] ?? [];
		if ( empty( $cmt_fields ) ) {
			return;
		}

		$meta_query = $query->get( 'meta_query' );
		$partials   = $this->split_meta_query_for_cmt(
			is_array( $meta_query ) ? $meta_query : [],
			$cmt_fields
		);

		$orderby_in  = $query->get( 'orderby' );
		$order_in    = $query->get( 'order' );
		$meta_key_in = $query->get( 'meta_key' );

		$order_list   = [];
		$unset_orders = false;
		$new_orderby  = null;

		if ( $orderby_in ) {
			$orderby_norm = is_array( $orderby_in )
				? $orderby_in
				: [ $orderby_in => $order_in ];

			foreach ( $orderby_norm as $key => $dir ) {
				$custom_key = false;

				if ( in_array( $key, [ 'meta_value_num', 'meta_value' ], true )
					&& in_array( $meta_key_in, $cmt_fields, true )
				) {
					$suffix     = ( 'meta_value_num' === $key ) ? '+0' : '';
					$custom_key = $meta_key_in . $suffix;
					$unset_orders = true;
					$query->set( 'meta_key', null );
				}

				if ( ! empty( $partials['custom_query'] )
					&& isset( $partials['custom_query'][ $key ] )
				) {
					$clause          = $partials['custom_query'][ $key ];
					$custom_meta_key = $clause['key'] ?? '';
					$type            = $clause['type'] ?? '';
					$numeric         = [ 'TIMESTAMP', 'NUMERIC', 'DECIMAL', 'SIGNED' ];
					$suffix          = in_array( $type, $numeric, true ) ? '+0' : '';
					$custom_key      = $custom_meta_key . $suffix;
					$unset_orders    = true;
				}

				$order_list[] = [
					'custom_key'  => $custom_key,
					'order'       => $dir ?: 'DESC',
					'replacement' => false,
				];
			}

			if ( $unset_orders ) {
				$orderby_keys = array_keys( $orderby_norm );
				$rewritten    = [];
				$t            = time() * 10;

				foreach ( $orderby_keys as $i => $orig_key ) {
					$is_custom = ! empty( $order_list[ $i ]['custom_key'] );
					$key       = $is_custom ? "RAND(" . ( $t + $i ) . ")" : $orig_key;
					if ( $is_custom ) {
						$order_list[ $i ]['replacement'] = $key;
					}
					$rewritten[ $key ] = $order_list[ $i ]['order'];
				}
				$new_orderby = $rewritten;
			}
		}

		if ( ! empty( $partials['custom_query'] ) || $unset_orders ) {
			// Match JE: CMT table goes through DB::table(), which prepends
			// $wpdb->prefix + static::$prefix. Manager::get_table_name() on
			// its own returns the UNPREFIXED slug-derived name and would
			// yield SQL referencing a table that does not physically exist.
			$db          = $manager->get_db_instance( $matching['object_slug'], $matching['fields'] ?? [] );
			$cmt_table   = $db->table();

			$query->set( 'custom_table_query', [
				'table' => $cmt_table,
				'query' => $partials['custom_query'] ?: [],
				'order' => $order_list,
			] );
			$query->set( 'meta_query', $partials['meta_query'] ?: [] );
			if ( null !== $new_orderby ) {
				$query->set( 'orderby', $new_orderby );
			}
			$query->set( '_jqbeb_je_cmt', $matching['object_slug'] );
		}
	}

	/**
	 * Recursively split a meta_query into CMT-stored vs postmeta-stored
	 * clauses. Mirror of `Query::exctract_meta_query_partials()` in JE
	 * (`post-types/custom-tables/query.php:470`).
	 *
	 * @return array{custom_query: array|false, meta_query: array|false}
	 */
	private function split_meta_query_for_cmt( array $meta_query, array $cmt_fields ): array {
		$result           = [ 'custom_query' => false, 'meta_query' => false ];
		$custom_query     = [];
		$plain_meta_query = [];
		$relation         = false;

		foreach ( $meta_query as $key => $clause ) {
			if ( 'relation' === $key ) {
				$relation = $clause;
				continue;
			}
			if ( ! is_array( $clause ) ) {
				continue;
			}

			if ( isset( $clause['key'] ) ) {
				if ( in_array( $clause['key'], $cmt_fields, true ) ) {
					$custom_query[ $key ] = $clause;
				} else {
					$plain_meta_query[ $key ] = $clause;
				}
			} else {
				$sub = $this->split_meta_query_for_cmt( $clause, $cmt_fields );
				if ( ! empty( $sub['meta_query'] ) ) {
					$plain_meta_query[ $key ] = $sub['meta_query'];
				}
				if ( ! empty( $sub['custom_query'] ) ) {
					$custom_query[ $key ] = $sub['custom_query'];
				}
			}
		}

		if ( ! empty( $custom_query ) ) {
			if ( $relation ) {
				$custom_query['relation'] = $relation;
			}
			$result['custom_query'] = $custom_query;
		}
		if ( ! empty( $plain_meta_query ) ) {
			if ( $relation ) {
				$plain_meta_query['relation'] = $relation;
			}
			$result['meta_query'] = $plain_meta_query;
		}
		return $result;
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
	 *
	 * Order matters: get_query_args() MUST run before set_filtered_prop()
	 * on a freshly-fetched JE query. JetEngine's Posts_Query::set_filtered_prop
	 * for `_page` writes directly into `$this->final_query['paged']`
	 * (jet-engine/.../queries/posts.php:320). When `final_query` is still
	 * null (lazy-built by setup_query() inside get_query_args()), that
	 * assignment AUTOVIVIFIES `final_query` as a degenerate 2-key array
	 * containing only `paged` + `page`. The subsequent get_query_args()
	 * then sees a non-null `final_query` and skips setup_query() — so the
	 * configured post_type / meta_query / tax_query / orderby never enter
	 * `final_query` and the returned args lose everything except the page
	 * we just set. Result: WP_Query runs with paged=N and Etch-preset
	 * defaults for everything else, which on a JSF-filtered loop yields
	 * zero rows (the JSF meta/geo clauses match against a post_type='any'
	 * universe instead of the configured CPT).
	 *
	 * Mirror of the pattern already in extract_ids_from_get_items() (Merged
	 * / SQL / Data Store path). Calling get_query_args() first triggers
	 * setup_query() lazily; the second call returns the populated args.
	 */
	private function get_args_with_pagination( $je_query ): ?array {
		$this->in_extraction = true;
		try {
			// Force setup_query() to run via get_query_args() so final_query
			// is populated with the configured args before set_filtered_prop
			// can autovivify it. See method-level docblock.
			if ( method_exists( $je_query, 'get_query_args' ) ) {
				$je_query->get_query_args();
			}

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
		if ( ! JSF_Bridge::$in_ajax_render && ( wp_doing_ajax() || is_admin() ) ) {
			return $block_content;
		}
		if ( ( $block['blockName'] ?? '' ) !== 'etch/element' ) {
			return $block_content;
		}
		$classes = self::get_classes( $block );
		if ( ! preg_match( '/\bje-etch-loop\b/i', $classes ) ) {
			return $block_content;
		}

		// Wrapper close — drop our wrapper-level tracking.
		array_pop( $this->wrapper_data_open );

		// Safety-net State_Stack pop in case etch/loop pushed but no
		// matching dispatcher fired (e.g. preset type vs JE query type
		// mismatch — JE query is posts but Etch preset is users-loop).
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
