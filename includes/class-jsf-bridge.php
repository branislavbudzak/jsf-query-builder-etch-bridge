<?php
/**
 * JSF ↔ Etch Query Loop bridge.
 *
 * Registers "Etch Loop" as a JSF Provider, so JSF filter / pagination / sort
 * blocks in Gutenberg can drive any Etch Query Loop on archive, single, or
 * page templates.
 *
 * @package JQBEB
 */

namespace JQBEB;

defined( 'ABSPATH' ) || exit;

class JSF_Bridge {

	private State_Stack $stack;

	/**
	 * Stack of query_ids for currently-open jsf-etch-loop wrappers.
	 *
	 * Indirection: we used to push directly to State_Stack at the
	 * jsf-etch-loop wrapper's pre_render_block. But the wrapper also
	 * contains dynamic-data sub-queries (e.g. a single partner CPT
	 * lookup for a logo / name dereference) that fire BEFORE the
	 * etch/loop child block renders. Those sub-queries' pre_get_posts
	 * popped State_Stack first, so the actual loop query landed with
	 * an empty stack and was never tagged → JSF provider's paged /
	 * filter args never got applied.
	 *
	 * Now we push State_Stack only at the etch/loop child's
	 * pre_render_block — that's the block whose VERY next WP_Query
	 * IS the loop's own query, not a sibling lookup. This array
	 * remembers per-wrapper which qid to push when the etch/loop
	 * fires (push on wrapper open, pop on wrapper close).
	 *
	 * @var string[]
	 */
	private array $wrapper_qids_open = [];

	/**
	 * In-process AJAX render flag.
	 *
	 * Set to true by JSF_Provider::ajax_get_content() while it renders the
	 * cached wrapper block tree directly (instead of HTTP loopback). Hooks
	 * that normally bail on wp_doing_ajax() / is_admin() must run during
	 * this render so the loop block can re-tag its WP_Query and JSF's
	 * provider hook can re-apply filter / paged args.
	 *
	 * Static so JE_Bridge can also read it without a cross-instance handle.
	 *
	 * @var bool
	 */
	public static bool $in_ajax_render = false;

	/**
	 * Per-request memoisation for Range filter dynamic min/max recomputed
	 * from JE Custom Meta Tables. Keyed by JSF filter post ID. Stores
	 * [float $min, float $max] on hit, [null, null] on miss (so we don't
	 * re-run the SQL when the same filter instance is constructed multiple
	 * times within one request — sitemap, hierarchy, dynamic tags, etc.).
	 *
	 * @var array<int, array{0: ?float, 1: ?float}>
	 */
	private array $range_minmax_cache = [];

	/**
	 * Transient key for storing a cached wrapper block tree, keyed by URL
	 * path + query_id. JSF_Provider reads this in ajax_get_content() to
	 * render the loop directly without a full-page HTTP loopback.
	 */
	public static function block_cache_key( string $url_path, string $query_id ): string {
		return 'jqbeb_block_' . md5( $url_path . '|' . $query_id );
	}

	public function __construct() {
		$this->stack = new State_Stack();

		add_filter( 'jet-smart-filters/blocks/allowed-providers', [ $this, 'add_provider_to_dropdown' ] );

		add_action( 'parse_request', [ $this, 'initial_load_filter_gate' ], 1 );
		add_filter( 'pre_render_block', [ $this, 'on_pre_render_block' ], 5, 2 );
		add_action( 'pre_get_posts', [ $this, 'tag_query_for_jsf' ], 50 );
		add_filter( 'render_block', [ $this, 'on_render_block' ], 999, 2 );

		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_count_script' ] );
		// Priority 5 so it runs before wp_print_footer_scripts (p20).
		// By then loops have rendered and query props are populated.
		add_action( 'wp_footer', [ $this, 'output_footer_data' ], 5 );

		add_filter( 'jet-smart-filters/pre-get-indexed-data', [ $this, 'compute_indexed_counts' ], 10, 4 );

		// Recompute JSF Range filter dynamic min/max from JE Custom Meta Tables
		// when the filter's meta_key is a registered CMT field. JSF's built-in
		// jet_smart_filters_meta_values callback queries wp_postmeta and returns
		// NULL for CMT-stored fields, leaving the slider with empty bounds.
		add_filter( 'jet-smart-filters/filter-instance/args', [ $this, 'override_range_min_max_for_cmt' ], 20, 2 );

		// Live `dynamic_range` recompute on AJAX filter changes. JSF 3.8.0+
		// pushes a `dynamic_range[type][]: [vars]` payload in every AJAX
		// request and expects the provider's response to include
		// `dynamic_range[var]: {min, max}` so the slider can update its bounds
		// based on the current filter context. Crocoblock-native providers
		// hook this; our `etch-loop` provider would otherwise leave the field
		// missing, so JSF can't update slider bounds when other filters change.
		add_filter( 'jet-smart-filters/render/ajax/data', [ $this, 'add_dynamic_range_to_ajax_response' ] );

		add_action( 'jet-smart-filters/providers/register', [ $this, 'register_provider' ] );
	}

	public function add_provider_to_dropdown( $providers ) {
		$providers['etch-loop'] = __( 'Etch Loop', 'jsf-query-builder-etch-bridge' );
		return $providers;
	}

	/* -------------------- HELPERS -------------------- */

	public static function get_classes( array $block ): string {
		return (string) ( $block['attrs']['attributes']['class'] ?? '' );
	}

	public static function find_loop_block( array $block ): ?array {
		if ( ( $block['blockName'] ?? '' ) === 'etch/loop' ) {
			return $block;
		}
		foreach ( $block['innerBlocks'] ?? [] as $inner ) {
			if ( is_array( $inner ) ) {
				$found = self::find_loop_block( $inner );
				if ( $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	public static function extract_query_id( string $classes ): string {
		if ( preg_match( '/\bjsf-etch-q-([a-z0-9_\-]+)\b/i', $classes, $m ) ) {
			return $m[1];
		}
		return 'default';
	}

	public static function current_path(): string {
		$uri  = $_SERVER['REQUEST_URI'] ?? '/';
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		return $path ?: '/';
	}

	private static function has_filter_params(): bool {
		foreach ( array_keys( $_REQUEST ) as $key ) {
			$key = (string) $key;
			if ( preg_match( '/^_(meta_query|tax_query|date_query|_s|sort|alphabet)/', $key ) ) {
				return true;
			}
			if ( $key === 'geo_query' ) {
				return true;
			}
		}
		return false;
	}

	public static function is_loopback(): bool {
		return ! empty( $_SERVER['HTTP_X_JQBEB_LOOPBACK'] );
	}

	/* -------------------- INITIAL-LOAD FILTER GATE -------------------- */

	public function initial_load_filter_gate(): void {
		if ( wp_doing_ajax() || is_admin() ) {
			return;
		}
		if ( ! empty( $_REQUEST['jsf'] ) ) {
			return;
		}
		if ( ! self::has_filter_params() ) {
			return;
		}
		if ( empty( $_REQUEST['provider'] ) ) {
			$_REQUEST['provider'] = 'etch-loop';
		}
		$_REQUEST['jsf'] = $_REQUEST['provider'];
	}

	/* -------------------- CAPTURE STATE -------------------- */

	public function on_pre_render_block( $pre, $block ) {
		if ( ! self::$in_ajax_render && ( wp_doing_ajax() || is_admin() ) ) {
			return $pre;
		}

		$bn = $block['blockName'] ?? '';

		// Wrapper open: remember qid so we can push it onto State_Stack
		// when this wrapper's etch/loop child renders.
		if ( $bn === 'etch/element' ) {
			$classes = self::get_classes( $block );
			if ( preg_match( '/\bjsf-etch-loop\b/i', $classes ) ) {
				$this->wrapper_qids_open[] = self::extract_query_id( $classes );
			}
			return $pre;
		}

		// Loop block inside an open jsf-etch-loop wrapper: push the topmost
		// wrapper qid. The very next WP_Query that fires (Etch's loop
		// handler instantiates one in get_loop_data) is the loop's own
		// query — tag_query_for_jsf p50 will read this qid, tag the query,
		// and pop. Sub-queries that fired before us (dynamic data lookups)
		// landed on an empty stack and were correctly skipped.
		if ( $bn === 'etch/loop' && ! empty( $this->wrapper_qids_open ) ) {
			$this->stack->push( end( $this->wrapper_qids_open ) );
		}

		return $pre;
	}

	public function tag_query_for_jsf( \WP_Query $query ): void {
		if ( ! self::$in_ajax_render && ( is_admin() || wp_doing_ajax() ) ) {
			return;
		}
		if ( $query->is_main_query() ) {
			return;
		}

		$query_id = $this->stack->current();
		if ( ! $query_id ) {
			return;
		}

		$post_type = $query->get( 'post_type' );
		$pt_check  = is_array( $post_type ) ? reset( $post_type ) : $post_type;
		if ( is_string( $pt_check ) && str_starts_with( $pt_check, 'wp_' ) ) {
			return;
		}

		if ( $query->get( 'jet_smart_filters' ) ) {
			return;
		}

		$query->set( 'jet_smart_filters', 'etch-loop/' . $query_id );

		// Register this loop's base query with JSF so the Indexer can find
		// us in get_default_queries() and Filter Indexer counts work.
		//
		// JSF's flow:
		//   - prepare_localized_data (initial page load) iterates
		//     get_default_queries(); providers without an entry are skipped
		//     entirely → no indexed_data localized to JS → no counts.
		//   - AJAX filter requests carry query_args sent from JS, which JS
		//     populated from the same localized default_queries; without
		//     our entry, JS sends empty args → indexer queries the wrong
		//     post type → tax_query / meta_query counts are wrong or zero.
		//
		// All other JSF providers (epro-loop-grid, woocommerce-archive,
		// jet-engine-calendar, etc.) call this same method at render time.
		// We do it here at pre_get_posts p50 because by this point the JE
		// bridge p40 has injected its base args (post_type, meta_query,
		// tax_query, orderby, etc.) but JSF p60 has not yet merged user
		// filter values — that is exactly the "default" baseline JSF
		// expects. CMT split (p70) has not run either, so meta_query is
		// still in its raw JE form; the indexer's count_query will
		// re-trigger JE's own pre_get_posts splitter when it instantiates
		// a fresh WP_Query, so the CMT JOIN gets emitted there too.
		if ( function_exists( 'jet_smart_filters' ) ) {
			$relevant_keys = [
				'post_type',
				'post_status',
				'posts_per_page',
				'meta_query',
				'tax_query',
				'date_query',
				'orderby',
				'order',
				'meta_key',
				'post__in',
				'post__not_in',
				'paged',
			];
			$default_args = array_intersect_key(
				(array) $query->query_vars,
				array_flip( $relevant_keys )
			);

			// JSF's Indexer_Data::prepare_ajax_data calls
			// merge_query_args( get_default_queries(), get_query_args() )
			// which array_merge()s on the keys below. Defaults reach that
			// code via $_REQUEST['defaults'] (form-encoded POST), which
			// stringifies every scalar — so a stored `meta_query => false`
			// arrives as the string "false", passes JSF's `! empty()` gate,
			// and fatals `array_merge("false", ...)`. WP_Query exposes such
			// non-array values for these keys whenever the loop preset
			// doesn't populate them (Etch presets set meta_query=false).
			// Drop any non-array value here so JSF only ever sees arrays.
			foreach ( [ 'meta_query', 'tax_query', 'post__not_in' ] as $merged_key ) {
				if ( isset( $default_args[ $merged_key ] ) && ! is_array( $default_args[ $merged_key ] ) ) {
					unset( $default_args[ $merged_key ] );
				}
			}

			jet_smart_filters()->query->store_provider_default_query(
				'etch-loop',
				$default_args,
				$query_id
			);
		}

		$this->stack->pop();
	}

	public function on_render_block( $block_content, $block ) {
		if ( ! self::$in_ajax_render && ( wp_doing_ajax() || is_admin() ) ) {
			return $block_content;
		}
		if ( ( $block['blockName'] ?? '' ) !== 'etch/element' ) {
			return $block_content;
		}
		$classes = self::get_classes( $block );
		if ( ! preg_match( '/\bjsf-etch-loop\b/i', $classes ) ) {
			return $block_content;
		}

		$query_id = self::extract_query_id( $classes );

		// Cache the wrapper block tree for direct AJAX render. Only on
		// initial page load (NOT during loopback, NOT during in-process
		// AJAX render — those are reads, not writes). The cached tree
		// lets JSF_Provider::ajax_get_content render the loop in-process
		// instead of doing a full-page HTTP loopback (~10-50× faster).
		if ( ! self::is_loopback() && ! self::$in_ajax_render ) {
			$cache_key = self::block_cache_key( self::current_path(), $query_id );
			set_transient(
				$cache_key,
				[
					'block'   => $block,
					'post_id' => (int) get_queried_object_id(),
				],
				HOUR_IN_SECONDS
			);
		}

		// Only emit JSF props comment during loopback — picked up by the
		// AJAX response parser to update found_posts / max_num_pages.
		// Not needed for in-process render: provider reads props directly
		// from JSF after the in-process render completes.
		if ( self::is_loopback() && function_exists( 'jet_smart_filters' ) ) {
			$props = jet_smart_filters()->query->get_query_props( 'etch-loop', $query_id );
			if ( is_array( $props ) ) {
				$payload = [ 'query_id' => $query_id, 'props' => $props ];
				$encoded = base64_encode( wp_json_encode( $payload ) );
				$block_content .= '<!--JQBEB-PROPS:' . $encoded . '-->';
			}
		}

		// Wrapper close — pop the wrapper qid we tracked for this scope.
		array_pop( $this->wrapper_qids_open );

		// Safety-net pop: if etch/loop pushed but no inner WP_Query fired
		// (e.g. preset missing), the State_Stack still has the qid. Pop
		// it here so the next render starts with a clean stack.
		if ( ! $this->stack->is_empty() ) {
			$this->stack->pop();
		}

		return $block_content;
	}

	/* -------------------- COUNT SCRIPT -------------------- */

	public function enqueue_count_script(): void {
		wp_enqueue_script(
			'jqbeb-count',
			JQBEB_URL . 'assets/js/count.js',
			[],
			$this->asset_version( 'assets/js/count.js' ),
			true
		);
		// Range filter pending-state resolver — bridges JSF 3.8.0.1+ async
		// dynamic-range pattern for our 'etch-loop' provider. See
		// assets/js/range-fill.js for full notes.
		wp_enqueue_script(
			'jqbeb-range-fill',
			JQBEB_URL . 'assets/js/range-fill.js',
			[ 'jet-smart-filters' ],
			$this->asset_version( 'assets/js/range-fill.js' ),
			true
		);
	}

	/**
	 * Cache-busting version for an enqueued asset.
	 *
	 * Uses the file's mtime so any edit (or fresh deploy that rewrites the
	 * file) automatically changes the `?ver=` query string, forcing CDN
	 * (BunnyCDN, LiteSpeed, etc.) and browser caches to refetch. Falls back
	 * to JQBEB_VERSION if mtime can't be read.
	 */
	private function asset_version( string $relative_path ): string {
		$abs   = JQBEB_DIR . ltrim( $relative_path, '/' );
		$mtime = is_readable( $abs ) ? @filemtime( $abs ) : false;
		if ( $mtime ) {
			return JQBEB_VERSION . '.' . $mtime;
		}
		return JQBEB_VERSION;
	}

	public function output_footer_data(): void {
		if ( wp_doing_ajax() || is_admin() ) {
			return;
		}
		if ( ! function_exists( 'jet_smart_filters' ) ) {
			return;
		}

		$all_props = jet_smart_filters()->query->get_query_props();
		if ( ! is_array( $all_props ) ) {
			$all_props = [];
		}

		$payload = isset( $all_props['etch-loop'] ) && is_array( $all_props['etch-loop'] )
			? [ 'etch-loop' => $all_props['etch-loop'] ]
			: [ 'etch-loop' => (object) [] ];

		wp_add_inline_script(
			'jqbeb-count',
			'window.JQBEBData = ' . wp_json_encode( $payload ) . ';',
			'before'
		);
	}

	/* -------------------- INDEXER COUNTS -------------------- */

	public function compute_indexed_counts( $pre, $provider_key, $query_args, $indexer_data ) {
		if ( ! is_string( $provider_key ) || strpos( $provider_key, 'etch-loop/' ) !== 0 ) {
			return $pre;
		}
		if ( ! is_array( $query_args ) ) {
			return $pre;
		}

		$indexing_data = isset( $indexer_data->indexing_data[ $provider_key ] )
			? $indexer_data->indexing_data[ $provider_key ]
			: [];

		if ( empty( $indexing_data ) ) {
			return [];
		}

		$count_args                        = $query_args;
		$count_args['posts_per_page']      = -1;
		$count_args['fields']              = 'ids';
		$count_args['no_found_rows']       = true;
		$count_args['paged']               = 0;
		$count_args['ignore_sticky_posts'] = true;
		unset( $count_args['_query_type'] );

		$count_query  = new \WP_Query( $count_args );
		$matching_ids = $count_query->posts;

		if ( empty( $matching_ids ) ) {
			return [];
		}

		global $wpdb;
		$ids_in       = implode( ',', array_map( 'absint', $matching_ids ) );
		$indexed_data = [];

		// Detect CMT context once: if the loop's post_type uses JE Custom
		// Meta Tables, meta_query indexed counts must read from the custom
		// table (column-per-field, object_ID FK), not wp_postmeta.
		[ $cmt_table, $cmt_fields ] = $this->detect_cmt_for_args( $query_args );

		foreach ( $indexing_data as $query_type => $type_data ) {

			$indexed_data[ $query_type ] = [];

			if ( $query_type === 'tax_query' && is_array( $type_data ) ) {

				foreach ( $type_data as $taxonomy => $term_ids ) {

					$sql = $wpdb->prepare(
						"SELECT t.term_id, COUNT(DISTINCT tr.object_id) AS cnt
						 FROM {$wpdb->term_relationships} tr
						 INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
						 INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
						 WHERE tr.object_id IN ($ids_in)
						   AND tt.taxonomy = %s
						 GROUP BY t.term_id",
						$taxonomy
					);

					$rows = $wpdb->get_results( $sql );
					foreach ( $rows as $row ) {
						$indexed_data['tax_query'][ $taxonomy ][ (string) $row->term_id ] = (int) $row->cnt;
					}
				}

			} elseif ( $query_type === 'meta_query' && is_array( $type_data ) ) {

				foreach ( $type_data as $meta_key => $values ) {

					if ( strpos( (string) $meta_key, '|' ) !== false ) {
						continue;
					}

					$keys = strpos( $meta_key, ',' ) !== false
						? array_map( 'trim', explode( ',', $meta_key ) )
						: [ $meta_key ];

					$cmt_keys = $cmt_table
						? array_values( array_intersect( $keys, $cmt_fields ) )
						: [];
					$pm_keys  = array_values( array_diff( $keys, $cmt_keys ) );

					$bucket = [];

					if ( ! empty( $cmt_keys ) ) {
						// CMT table is wide: one column per registered field,
						// object_ID FK to wp_posts.ID. Aggregate per column,
						// then merge into a single value→count bucket so
						// multi-key filters still display unified counts.
						foreach ( $cmt_keys as $field ) {
							$col = $this->sanitize_cmt_column( $field );
							if ( ! $col ) {
								continue;
							}
							$rows = $wpdb->get_results(
								"SELECT `{$col}` AS meta_value, COUNT(DISTINCT object_ID) AS cnt
								 FROM `{$cmt_table}`
								 WHERE object_ID IN ($ids_in)
								   AND `{$col}` IS NOT NULL
								   AND `{$col}` <> ''
								 GROUP BY `{$col}`"
							);
							foreach ( $rows as $row ) {
								$val = (string) $row->meta_value;
								$existing = $bucket[ $val ] ?? 0;
								$bucket[ $val ] = $existing + (int) $row->cnt;
							}
						}
					}

					if ( ! empty( $pm_keys ) ) {
						$placeholders = implode( ',', array_fill( 0, count( $pm_keys ), '%s' ) );
						$sql          = $wpdb->prepare(
							"SELECT meta_value, COUNT(DISTINCT post_id) AS cnt
							 FROM {$wpdb->postmeta}
							 WHERE post_id IN ($ids_in)
							   AND meta_key IN ($placeholders)
							 GROUP BY meta_value",
							...$pm_keys
						);
						$rows         = $wpdb->get_results( $sql );
						foreach ( $rows as $row ) {
							$val            = (string) $row->meta_value;
							$existing       = $bucket[ $val ] ?? 0;
							$bucket[ $val ] = $existing + (int) $row->cnt;
						}
					}

					$indexed_data['meta_query'][ $meta_key ] = $bucket;
				}
			}
		}

		return $indexed_data;
	}

	/**
	 * If the query post_type uses JE Custom Meta Tables, return [table, fields].
	 * Otherwise [null, []].
	 *
	 * @return array{0: ?string, 1: array<int,string>}
	 */
	private function detect_cmt_for_args( array $query_args ): array {
		if ( ! class_exists( '\Jet_Engine\CPT\Custom_Tables\Manager' ) ) {
			return [ null, [] ];
		}
		$manager = \Jet_Engine\CPT\Custom_Tables\Manager::instance();
		if ( empty( $manager->storages ) ) {
			return [ null, [] ];
		}
		$post_types = (array) ( $query_args['post_type'] ?? [] );
		foreach ( $manager->storages as $storage ) {
			if ( ( $storage['object_type'] ?? '' ) !== 'post' ) {
				continue;
			}
			if ( in_array( $storage['object_slug'] ?? '', $post_types, true ) ) {
				// Use DB::table() so we get the $wpdb->prefix-prefixed name,
				// matching how JE writes the table in custom_table_query.
				$db = $manager->get_db_instance( $storage['object_slug'], $storage['fields'] ?? [] );
				return [
					$db->table(),
					$storage['fields'] ?? [],
				];
			}
		}
		return [ null, [] ];
	}

	/**
	 * Allow only [a-zA-Z0-9_] in CMT column names — they're interpolated
	 * directly into SQL because $wpdb->prepare cannot bind identifiers.
	 * Membership in the registered CMT fields list is the trust source;
	 * this is a defence-in-depth strip.
	 */
	private function sanitize_cmt_column( string $col ): string {
		return preg_replace( '/[^A-Za-z0-9_]/', '', $col ) ?: '';
	}

	/* -------------------- RANGE FILTER (CMT) -------------------- */

	/**
	 * Hooked on `jet-smart-filters/filter-instance/args` (priority 20).
	 *
	 * JSF's "Range" filter has a "Get min/max dynamically: Get from custom
	 * storage by query meta key" option that resolves bounds via the
	 * jet_smart_filters_meta_values callback — which queries wp_postmeta.
	 * For JE CPTs using JetEngine Custom Meta Tables (CMT), the field's
	 * value lives in {cmt_table}.{column}, NOT wp_postmeta, so the SQL
	 * returns NULL and the slider has empty min/max.
	 *
	 * When the filter targets a CMT-registered meta_key, this method
	 * recomputes min/max from the CMT table(s), applies the same step
	 * rounding JSF does for max, and overrides the args.
	 *
	 * The bug is data-shape (where the meta lives), not provider-specific,
	 * so we don't gate on `content_provider === 'etch-loop'` — any JSF
	 * range filter targeting a CMT-registered meta_key benefits.
	 *
	 * @param array<string,mixed> $args
	 * @param object              $instance Jet_Smart_Filters_Filter_Instance
	 * @return array<string,mixed>
	 */
	public function override_range_min_max_for_cmt( $args, $instance ) {
		if ( ! is_array( $args ) ) {
			return $args;
		}
		if ( ( $args['query_type'] ?? '' ) !== 'meta_query' ) {
			return $args;
		}
		if ( ! is_object( $instance ) || ! isset( $instance->type ) ) {
			return $args;
		}
		if ( ! ( $instance->type instanceof \Jet_Smart_Filters_Range_Filter ) ) {
			return $args;
		}

		$filter_id = (int) ( $args['filter_id'] ?? 0 );
		if ( ! $filter_id ) {
			return $args;
		}

		$source_cb = get_post_meta( $filter_id, '_source_callback', true );

		// Two callbacks land in CMT territory:
		//
		// 1. `jet_smart_filters_meta_values` — JSF's built-in "Get from Post
		//    Meta by query meta key" callback (jet-smart-filters/includes/
		//    functions.php:67). It queries `wp_postmeta` directly. For CMT
		//    post types the meta lives in {cmt_table}.{column}, not
		//    `wp_postmeta`, so it returns NULL → JSF falls back to manual
		//    `_source_min` / `_source_max` (default 0/100).
		//
		// 2. `jet_engine_custom_storage_post_{slug}` — JE-NATIVE CMT
		//    callback registered by `\Jet_Engine\CPT\Custom_Tables\Query::
		//    register_range_min_max_callback` for each CMT storage. UI label
		//    is "{Post Type}: Get from custom storage by query meta key".
		//    JE's callback (custom-tables/query.php:73) queries the right
		//    table BUT has a known sharp edge: when the SQL returns NULL
		//    min/max (column has only NULL values, or SQL warns silently),
		//    JE returns `[ 'min' => null, 'max' => null ]`. JSF's
		//    `isset($data['min'])` on NULL evaluates to FALSE → falls back
		//    to manual `_source_min` / `_source_max` (default 0/100).
		//
		// We accept both. The JE-native form also lets us pin the lookup to
		// the explicit storage slug instead of inferring from field membership.
		$is_postmeta_cb = ( $source_cb === 'jet_smart_filters_meta_values' );
		$je_native_prefix = 'jet_engine_custom_storage_post_';
		$is_je_native_cb  = is_string( $source_cb )
			&& strpos( $source_cb, $je_native_prefix ) === 0;

		if ( ! $is_postmeta_cb && ! $is_je_native_cb ) {
			return $args;
		}

		$je_restrict_slug = $is_je_native_cb
			? substr( $source_cb, strlen( $je_native_prefix ) )
			: null;

		// Per-site / per-request escape valve.
		if ( ! apply_filters( 'jqbeb_range_cmt_override_enabled', true, $args, $instance ) ) {
			return $args;
		}

		$query_var = (string) ( $args['query_var'] ?? '' );
		if ( $query_var === '' ) {
			return $args;
		}

		// JSF accepts comma-separated meta keys for range. Pipe ('|') is the
		// query_var_suffix separator — do NOT split on it.
		$meta_keys = array_filter( array_map( 'trim', explode( ',', $query_var ) ) );
		if ( empty( $meta_keys ) ) {
			return $args;
		}

		// Cache: same filter instance can be constructed many times per
		// request (manager / sitemap / hierarchy / dynamic tags).
		if ( array_key_exists( $filter_id, $this->range_minmax_cache ) ) {
			[ $cached_min, $cached_max ] = $this->range_minmax_cache[ $filter_id ];
			if ( $cached_min === null || $cached_max === null ) {
				return $args;
			}
			return $this->apply_range_minmax( $args, $cached_min, $cached_max );
		}

		$targets = $this->find_cmt_targets_for_meta_keys( $meta_keys, $je_restrict_slug );
		if ( empty( $targets ) ) {
			$this->range_minmax_cache[ $filter_id ] = [ null, null ];
			return $args;
		}

		[ $min, $max ] = $this->compute_cmt_range_min_max( $targets );
		if ( $min === null || $max === null ) {
			$this->range_minmax_cache[ $filter_id ] = [ null, null ];
			return $args;
		}

		$this->range_minmax_cache[ $filter_id ] = [ $min, $max ];
		return $this->apply_range_minmax( $args, $min, $max );
	}

	/**
	 * Override $args['min'] / $args['max'] and snap max to the filter's step
	 * (mirror of Jet_Smart_Filters_Range_Filter::max_value_for_current_step).
	 *
	 * @param array<string,mixed> $args
	 */
	private function apply_range_minmax( array $args, float $min, float $max ): array {
		$step = (float) ( $args['step'] ?? 1 );
		if ( $step > 0 && $step !== 1.0 && $max >= $min ) {
			$steps_count = (int) ceil( ( $max - $min ) / $step );
			$max         = $steps_count * $step + $min;
		}
		$args['min'] = $min;
		$args['max'] = $max;
		return $args;
	}

	/**
	 * For each meta_key, find which JE CMT storages register a column of
	 * that name and return the (table, column, post_type) tuples.
	 *
	 * Public — also called from JSF_Provider for live `dynamic_range`
	 * recomputation on AJAX filter changes.
	 *
	 * @param string[] $meta_keys
	 * @param ?string  $restrict_to_slug When non-null (JE-native callback case),
	 *                                   only the storage whose `object_slug`
	 *                                   matches is considered — the user picked
	 *                                   a specific CPT in the JSF dropdown so
	 *                                   we should not silently widen the lookup.
	 * @return array<int, array{table: string, column: string, post_type: string}>
	 */
	public function find_cmt_targets_for_meta_keys( array $meta_keys, ?string $restrict_to_slug = null ): array {
		if ( ! class_exists( '\Jet_Engine\CPT\Custom_Tables\Manager' ) ) {
			return [];
		}
		$manager = \Jet_Engine\CPT\Custom_Tables\Manager::instance();
		if ( empty( $manager->storages ) ) {
			return [];
		}

		$targets = [];
		foreach ( $manager->storages as $storage ) {
			if ( ( $storage['object_type'] ?? '' ) !== 'post' ) {
				continue;
			}
			$fields = $storage['fields'] ?? [];
			if ( empty( $fields ) ) {
				continue;
			}
			$slug = (string) ( $storage['object_slug'] ?? '' );
			if ( $slug === '' ) {
				continue;
			}
			if ( $restrict_to_slug !== null && $slug !== $restrict_to_slug ) {
				continue;
			}
			$matched = array_values( array_intersect( $meta_keys, $fields ) );
			if ( empty( $matched ) ) {
				continue;
			}
			// $wpdb->prefix-prefixed name, matching how JE itself writes the
			// table in custom_table_query — same path used by detect_cmt_for_args.
			$db    = $manager->get_db_instance( $slug, $fields );
			$table = $db ? (string) $db->table() : '';
			if ( $table === '' ) {
				continue;
			}
			foreach ( $matched as $field ) {
				$col = $this->sanitize_cmt_column( (string) $field );
				if ( $col === '' ) {
					continue;
				}
				$targets[] = [
					'table'     => $table,
					'column'    => $col,
					'post_type' => $slug,
				];
			}
		}
		return $targets;
	}

	/**
	 * Run MIN(FLOOR())/MAX(CEILING()) per (table, column, post_type) tuple
	 * and aggregate. Mirrors the WHERE-status logic of JSF's own
	 * jet_smart_filters_meta_values (the filter `jet-smart-filters/dynamic-min-max/search-statuses`).
	 *
	 * Restricting by p.post_type keeps results scoped when two CMT
	 * storages share a column name (rare but possible).
	 *
	 * Public — also called from JSF_Provider for live `dynamic_range`
	 * recomputation on AJAX filter changes (with `$restrict_to_object_ids`
	 * non-null to scope to the post-IDs that match the current filter
	 * context minus the slider's own clause).
	 *
	 * @param array<int, array{table: string, column: string, post_type: string}> $targets
	 * @param ?int[] $restrict_to_object_ids When non-null, additionally
	 *                                       restricts the SQL to
	 *                                       `t.object_ID IN (...)` — used by
	 *                                       the live recalc path to scope
	 *                                       bounds to the current filtered
	 *                                       result set.
	 * @return array{0: ?float, 1: ?float}
	 */
	public function compute_cmt_range_min_max( array $targets, ?array $restrict_to_object_ids = null ): array {
		global $wpdb;

		$statuses = apply_filters( 'jet-smart-filters/dynamic-min-max/search-statuses', [ 'publish' ] );
		$statuses = array_values( array_filter( array_map( 'strval', (array) $statuses ) ) );
		if ( empty( $statuses ) ) {
			$statuses = [ 'publish' ];
		}
		$status_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		// Live-recalc path: caller has a result-set of post IDs from the
		// current filter context. Empty input → no rows match → null bounds.
		$ids_in_clause = '';
		if ( $restrict_to_object_ids !== null ) {
			$clean_ids = array_values( array_filter(
				array_map( 'absint', $restrict_to_object_ids ),
				fn ( $id ) => $id > 0
			) );
			if ( empty( $clean_ids ) ) {
				return [ null, null ];
			}
			// absint guarantees integer-only — safe to interpolate as IN list.
			$ids_in_clause = ' AND t.object_ID IN (' . implode( ',', $clean_ids ) . ')';
		}

		$min = null;
		$max = null;

		foreach ( $targets as $t ) {
			// $t['table'] and $t['column'] are not bind-able as identifiers;
			// they are sanitized via sanitize_cmt_column() and are sourced
			// from Manager::$storages[*]['fields'] (trust source).
			$sql = "SELECT MIN(FLOOR(t.`{$t['column']}`)) AS mn,
			               MAX(CEILING(t.`{$t['column']}`)) AS mx
			        FROM `{$t['table']}` AS t
			        INNER JOIN {$wpdb->posts} AS p ON p.ID = t.object_ID
			        WHERE t.`{$t['column']}` IS NOT NULL
			          AND t.`{$t['column']}` <> ''
			          AND p.post_type    = %s
			          AND p.post_status IN ({$status_placeholders})"
			        . $ids_in_clause;

			$prepared = $wpdb->prepare(
				$sql,
				array_merge( [ $t['post_type'] ], $statuses )
			);
			$row = $wpdb->get_row( $prepared );
			if ( ! $row ) {
				continue;
			}
			if ( $row->mn !== null && $row->mn !== '' ) {
				$candidate = (float) $row->mn;
				$min       = ( $min === null ) ? $candidate : min( $min, $candidate );
			}
			if ( $row->mx !== null && $row->mx !== '' ) {
				$candidate = (float) $row->mx;
				$max       = ( $max === null ) ? $candidate : max( $max, $candidate );
			}
		}

		return [ $min, $max ];
	}

	/* -------------------- LIVE RANGE RECALC (AJAX) -------------------- */

	/**
	 * Hooked on `jet-smart-filters/render/ajax/data` (JSF render.php:340) —
	 * fires after the provider's content has been rendered, just before
	 * `wp_send_json( $args )`.
	 *
	 * JSF 3.8.0+ JS subscribes to `request/ajax-data` and pushes a
	 * `dynamic_range[type][]: <queryVar>` entry into the AJAX request for
	 * every range filter on the page that has `data-dynamic-range-pending`.
	 * After the response comes back, JSF reads `response.dynamic_range[var]`
	 * for each range filter and calls `updateRangeBounds({min, max})` to
	 * narrow the slider to the bounds available within the current filter
	 * context. Crocoblock's own providers populate this server-side; our
	 * `etch-loop` provider has to do it explicitly.
	 *
	 * For each requested var:
	 *   1. Resolve CMT (table, column, post_type) targets via the same path
	 *      as the page-load override (`find_cmt_targets_for_meta_keys`).
	 *   2. Build a WP_Query that mirrors the current filter context but
	 *      EXCLUDES this var's own clause — otherwise the slider's bounds
	 *      would collapse to the user's current selection and they could
	 *      never widen it again.
	 *   3. Run the query for IDs only.
	 *   4. Run our CMT MIN/MAX SQL constrained by `t.object_ID IN (ids)`.
	 *   5. Inject `[$var => ['min' => …, 'max' => …]]` into the response.
	 *
	 * @param array<string,mixed> $args JSF response args (will be JSON-encoded).
	 * @return array<string,mixed>
	 */
	public function add_dynamic_range_to_ajax_response( $args ) {
		if ( ! is_array( $args ) ) {
			return $args;
		}

		// Only fire for our provider — JSF dispatches the same hook for every
		// provider's AJAX response, and other providers may handle dynamic_range
		// themselves. Reading the request directly avoids a JSF API roundtrip.
		$provider = isset( $_REQUEST['provider'] ) ? (string) $_REQUEST['provider'] : '';
		if ( $provider === '' || strpos( $provider, 'etch-loop' ) !== 0 ) {
			return $args;
		}

		// JSF JS posts the var list under a key like
		//   dynamic_range[[object Object],apply_min_max_callback][]=price_sale_gross
		//   dynamic_range[[object Object],apply_min_max_callback][]=mileage_km
		// because the JS uses the parsed `data-dynamic-range` JSON value
		// (a callable `[Query, "method"]`) as the bucket key — fine in JS
		// (object key gets coerced to a string), CATASTROPHIC in PHP:
		// `parse_str` cannot match the nested literal `[`/`]` and collapses
		// the whole `array<string, array<string>>` down to a single
		// `array[ '[object Object' => '<last var>' ]` — every var except
		// the LAST one is silently overwritten. Confirmed empirically.
		//
		// Workaround: read the raw POST body via `php://input` and walk
		// the `&`-delimited pairs ourselves, picking the value of every
		// key that starts with `dynamic_range`. WordPress doesn't consume
		// php://input during admin-ajax (it reads $_POST), so the stream
		// is intact here.
		$vars = $this->extract_dynamic_range_vars_from_raw_request();
		if ( empty( $vars ) ) {
			return $args;
		}

		// Per-site / per-request escape valve. Reuse the same opt-out filter
		// used by the page-load override so users have one knob for both.
		if ( ! apply_filters( 'jqbeb_range_cmt_override_enabled', true, $args, null ) ) {
			return $args;
		}

		$dynamic_range_out = [];
		foreach ( $vars as $var ) {
			$bounds = $this->compute_dynamic_range_for_var_in_context( $var );
			if ( $bounds === null ) {
				continue;
			}
			$dynamic_range_out[ $var ] = [
				'min' => $bounds[0],
				'max' => $bounds[1],
			];
		}

		if ( ! empty( $dynamic_range_out ) ) {
			$args['dynamic_range'] = $dynamic_range_out;
		}

		return $args;
	}

	/**
	 * Extract every range-filter `query_var` carried in the AJAX request's
	 * `dynamic_range[…][]=<var>` payload. Reads the raw POST body (or
	 * QUERY_STRING fallback) directly because PHP's `parse_str` cannot
	 * handle JSF's malformed bucket-key shape — see the long comment in
	 * `add_dynamic_range_to_ajax_response()`.
	 *
	 * @return string[] Deduplicated, non-empty var names in arrival order.
	 */
	private function extract_dynamic_range_vars_from_raw_request(): array {
		$body = '';
		if ( ! empty( $_SERVER['REQUEST_METHOD'] ) && strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) === 'POST' ) {
			$body = (string) file_get_contents( 'php://input' );
		}
		if ( $body === '' && ! empty( $_SERVER['QUERY_STRING'] ) ) {
			$body = (string) $_SERVER['QUERY_STRING'];
		}
		if ( $body === '' ) {
			return [];
		}

		$out = [];
		foreach ( explode( '&', $body ) as $pair ) {
			$eq = strpos( $pair, '=' );
			if ( $eq === false ) {
				continue;
			}
			$raw_key = substr( $pair, 0, $eq );
			$raw_val = substr( $pair, $eq + 1 );

			// urldecode the key to test the prefix correctly. The value is
			// always a single var name like `price_sale_gross` — no nested
			// brackets — so a plain urldecode is enough.
			$decoded_key = urldecode( $raw_key );
			if ( strpos( $decoded_key, 'dynamic_range' ) !== 0 ) {
				continue;
			}
			$val = trim( urldecode( $raw_val ) );
			if ( $val === '' ) {
				continue;
			}
			$out[] = $val;
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Resolve {min, max} for a single range filter `query_var` against the
	 * CURRENT filter context, EXCLUDING that var's own clause from the
	 * context. Returns `null` if the var has no CMT target, the context
	 * matches no posts, or the SQL aggregate yields NULL on both sides.
	 *
	 * @return array{0: float, 1: float}|null
	 */
	private function compute_dynamic_range_for_var_in_context( string $var ): ?array {
		// JSF accepts comma-separated meta keys for range. Pipe ('|') is the
		// query_var_suffix separator — do NOT split on it.
		$meta_keys = array_filter( array_map( 'trim', explode( ',', $var ) ) );
		if ( empty( $meta_keys ) ) {
			return null;
		}

		$targets = $this->find_cmt_targets_for_meta_keys( $meta_keys );
		if ( empty( $targets ) ) {
			return null;
		}

		$context_args = $this->build_recalc_context_args_excluding_var( $var );
		if ( ! is_array( $context_args ) || empty( $context_args['post_type'] ) ) {
			return null;
		}

		// IDs-only query — we just need the result set to constrain the
		// MIN/MAX SQL. -1 fetches the full filtered set; the CMT MIN/MAX is
		// then a single aggregate over those IDs.
		$context_args['fields']         = 'ids';
		$context_args['posts_per_page'] = -1;
		$context_args['no_found_rows']  = true;

		// Tag the recalc query with a sentinel so any debug code or future
		// pre_get_posts handler can identify it. The query itself doesn't
		// hit our existing JSF/JE merge hooks — those gate on a `jet_smart_filters`
		// query flag that we deliberately stripped in build_recalc_context_args.
		$context_args['_jqbeb_dynamic_range_recalc'] = $var;

		$wp_query = new \WP_Query( $context_args );
		$ids      = is_array( $wp_query->posts ) ? array_map( 'intval', $wp_query->posts ) : [];

		[ $min, $max ] = $this->compute_cmt_range_min_max( $targets, $ids );
		if ( $min === null || $max === null ) {
			return null;
		}

		return [ (float) $min, (float) $max ];
	}

	/**
	 * Reconstruct WP_Query args mirroring the current AJAX filter context
	 * EXCLUDING any clause whose query var matches `$exclude_var`.
	 *
	 * Why a custom merge instead of JSF's `get_query_args()`: JSF does a
	 * shallow `array_merge( $defaults, $_query )` which means the user's
	 * tax_query / meta_query clauses OVERWRITE the loop's JE-base
	 * tax_query / meta_query (post-type listing-status / availability_status
	 * etc.) — so any recalc would silently widen results past their JE-base
	 * scope. Mirrors `JSF_Provider::merge_jsf_into_query`'s deep-merge for
	 * the three multi-clause args.
	 *
	 * Strategy:
	 *   1. Mutate `$_REQUEST['query']` to drop self-var keys.
	 *   2. Re-parse via `get_query_from_request()` — populates the public
	 *      `jet_smart_filters()->query->_query` with parsed filter clauses
	 *      (no defaults).
	 *   3. Snapshot `_query` directly (it's public).
	 *   4. Restore `$_REQUEST` and re-parse so JSF state is intact for any
	 *      code after this hook.
	 *   5. Compose final args = defaults + parsed-clauses (deep-merged on
	 *      meta/tax/date_query).
	 *
	 * Self-exclusion only strips `_meta_query_{var}` / `_meta_query_{var}|{suffix}`
	 * keys — tax / date / sort keys can't reference a meta query_var.
	 *
	 * Returned args have JSF's own tag stripped so a fresh WP_Query with
	 * these args won't re-trigger `apply_jsf_to_tagged_query`.
	 *
	 * @return array<string,mixed>|null
	 */
	private function build_recalc_context_args_excluding_var( string $exclude_var ): ?array {
		if ( ! function_exists( 'jet_smart_filters' ) ) {
			return null;
		}

		$defaults = $_REQUEST['defaults'] ?? [];
		if ( ! is_array( $defaults ) ) {
			$defaults = [];
		}

		$saved_request_query = $_REQUEST['query'] ?? null;
		$filtered_query      = is_array( $saved_request_query ) ? $saved_request_query : [];
		foreach ( $filtered_query as $key => $_ ) {
			if ( $this->request_query_key_targets_var( (string) $key, $exclude_var ) ) {
				unset( $filtered_query[ $key ] );
			}
		}
		$_REQUEST['query'] = $filtered_query;

		try {
			jet_smart_filters()->query->get_query_from_request();
			$parsed = is_array( jet_smart_filters()->query->_query )
				? jet_smart_filters()->query->_query
				: [];
		} finally {
			if ( $saved_request_query === null ) {
				unset( $_REQUEST['query'] );
			} else {
				$_REQUEST['query'] = $saved_request_query;
			}
			// Re-parse with original to restore JSF's internal state.
			jet_smart_filters()->query->get_query_from_request();
		}

		// Compose: defaults form the base; parsed clauses layer on top
		// with per-clause deep merge for the three multi-clause args.
		$final = [];
		foreach ( [ 'post_type', 'post_status', 'post__in', 'post__not_in' ] as $passthrough ) {
			if ( isset( $defaults[ $passthrough ] ) ) {
				$final[ $passthrough ] = $defaults[ $passthrough ];
			}
		}

		foreach ( [ 'meta_query', 'tax_query', 'date_query' ] as $multi ) {
			$base   = ( ! empty( $defaults[ $multi ] ) && is_array( $defaults[ $multi ] ) )
				? $defaults[ $multi ]
				: [];
			$layer  = ( ! empty( $parsed[ $multi ] ) && is_array( $parsed[ $multi ] ) )
				? $parsed[ $multi ]
				: [];
			$merged = $this->merge_query_clauses( $base, $layer );
			if ( ! empty( $merged ) ) {
				$final[ $multi ] = $merged;
			}
		}

		// Honour search if the JSF parser produced one.
		if ( ! empty( $parsed['s'] ) ) {
			$final['s'] = $parsed['s'];
		}

		return $final;
	}

	/**
	 * Combine two `meta_query` / `tax_query` / `date_query`-shaped
	 * clause arrays AND-merging the clauses, retaining the highest-priority
	 * relation between them. WP_Query interprets numerically-keyed entries
	 * as nested clauses with implicit AND.
	 *
	 * @param array<int|string, mixed> $base
	 * @param array<int|string, mixed> $layer
	 * @return array<int|string, mixed>
	 */
	private function merge_query_clauses( array $base, array $layer ): array {
		$out = [];
		foreach ( $base as $k => $v ) {
			if ( $k === 'relation' ) {
				continue;
			}
			$out[] = $v;
		}
		foreach ( $layer as $k => $v ) {
			if ( $k === 'relation' ) {
				continue;
			}
			$out[] = $v;
		}
		if ( count( $out ) > 1 ) {
			$out['relation'] = 'AND';
		}
		return $out;
	}

	/**
	 * True iff a `$_REQUEST['query']` key targets the given query_var —
	 * i.e. matches `_meta_query_{var}` or `_meta_query_{var}|{suffix}`.
	 *
	 * Tax / date filters can't reference a meta query_var, so we only
	 * pattern-match against the `_meta_query_` prefix.
	 */
	private function request_query_key_targets_var( string $key, string $var ): bool {
		$prefix = '_meta_query_';
		if ( strpos( $key, $prefix ) !== 0 ) {
			return false;
		}
		$rest    = substr( $key, strlen( $prefix ) );
		$key_var = explode( '|', $rest, 2 )[0]; // strip suffix
		return $key_var === $var;
	}

	/* -------------------- PROVIDER REGISTRATION -------------------- */

	public function register_provider( $manager ): void {
		if ( ! class_exists( '\Jet_Smart_Filters_Provider_Base' ) ) {
			return;
		}
		require_once JQBEB_DIR . 'includes/class-jsf-provider.php';
		$manager->register_provider( '\JQBEB\JSF_Provider', ABSPATH . 'wp-load.php' );
	}
}
