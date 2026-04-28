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
		if ( wp_doing_ajax() || is_admin() ) {
			return $pre;
		}
		if ( ( $block['blockName'] ?? '' ) !== 'etch/element' ) {
			return $pre;
		}
		$classes = self::get_classes( $block );
		if ( ! preg_match( '/\bjsf-etch-loop\b/i', $classes ) ) {
			return $pre;
		}
		$this->stack->push( self::extract_query_id( $classes ) );
		return $pre;
	}

	public function tag_query_for_jsf( \WP_Query $query ): void {
		if ( is_admin() || wp_doing_ajax() ) {
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
		if ( wp_doing_ajax() || is_admin() ) {
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

		// Only emit JSF props comment during loopback — picked up by the
		// AJAX response parser to update found_posts / max_num_pages.
		if ( self::is_loopback() && function_exists( 'jet_smart_filters' ) ) {
			$props = jet_smart_filters()->query->get_query_props( 'etch-loop', $query_id );
			if ( is_array( $props ) ) {
				$payload = [ 'query_id' => $query_id, 'props' => $props ];
				$encoded = base64_encode( wp_json_encode( $payload ) );
				$block_content .= '<!--JQBEB-PROPS:' . $encoded . '-->';
			}
		}

		// Safety-net pop: if no inner WP_Query fired, capture state may
		// still hold this query_id. Pop until empty (cheap; usually empty).
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
			JQBEB_VERSION,
			true
		);
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

	/* -------------------- PROVIDER REGISTRATION -------------------- */

	public function register_provider( $manager ): void {
		if ( ! class_exists( '\Jet_Smart_Filters_Provider_Base' ) ) {
			return;
		}
		require_once JQBEB_DIR . 'includes/class-jsf-provider.php';
		$manager->register_provider( '\JQBEB\JSF_Provider', ABSPATH . 'wp-load.php' );
	}
}
