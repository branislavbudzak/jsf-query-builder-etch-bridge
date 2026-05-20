<?php
/**
 * Toggleable pagination diagnostics → browser console.
 *
 * Off by default — enable per-site with:
 *
 *   define( 'JQBEB_DEBUG_PAGINATION', true );
 *
 * in wp-config.php. Output lands in the browser DevTools console as a
 * single collapsed group per source:
 *
 *   ▸ [jqbeb-debug] page-load (N entries)
 *   ▸ [jqbeb-debug] ajax (N entries)
 *
 * Mechanism: each Debug::log() call appends to an in-memory buffer; the
 * buffer flushes via two channels per request:
 *
 *   1. Non-AJAX (initial page render) → inline <script> in wp_footer
 *      that sets `window.JQBEBDebug`. The consumer JS dumps it on
 *      document-ready.
 *
 *   2. JSF AJAX (filter / pagination / sort) → `_jqbeb_debug` array
 *      appended to the response via the `jet-smart-filters/render/ajax/data`
 *      filter. The consumer JS reads it from the jQuery ajaxComplete
 *      response and dumps it.
 *
 * Loopback path is not currently surfaced — the inner WP_Query runs in a
 * sub-request whose buffer is local to that PHP process. Most sites run
 * on the FAST direct-render path (v0.10.0+) so this is rarely missed.
 *
 * Used to diagnose the JSF Location & Distance + pagination interaction
 * documented in CHANGELOG entry for v1.1.1.
 *
 * @package JQBEB
 */

namespace JQBEB;

defined( 'ABSPATH' ) || exit;

class Debug {

	/**
	 * Accumulated entries for the current request.
	 *
	 * @var array<int, array{label: string, data: array<string,mixed>, ts: float}>
	 */
	private static array $buffer = [];

	private static bool $registered = false;

	public static function pagination_enabled(): bool {
		return defined( 'JQBEB_DEBUG_PAGINATION' ) && JQBEB_DEBUG_PAGINATION;
	}

	/**
	 * Append one entry to the buffer. Call sites use this exactly like an
	 * error_log — short label + small assoc array.
	 *
	 * @param string              $label Short tag for the log site.
	 * @param array<string,mixed> $data  Anything JSON-encodable.
	 */
	public static function log( string $label, array $data ): void {
		if ( ! self::pagination_enabled() ) {
			return;
		}
		self::$buffer[] = [
			'label' => $label,
			'data'  => $data,
			'ts'    => microtime( true ),
		];
	}

	/**
	 * Register the listeners that capture SQL + drain the buffer to the
	 * browser. Hooked once from Plugin::boot() when pagination debugging is
	 * on. Stays a no-op (early-returns) otherwise so the hook overhead is
	 * minimal.
	 */
	public static function register(): void {
		if ( ! self::pagination_enabled() || self::$registered ) {
			return;
		}
		self::$registered = true;

		// SQL + result snapshots — append to the buffer alongside the
		// boundary logs from apply_regular_to_posts / merge_jsf_into_query.
		add_filter( 'posts_request', [ self::class, 'on_posts_request' ], 999, 2 );
		add_filter( 'the_posts', [ self::class, 'on_the_posts' ], 999, 2 );

		// Drain channels.
		add_action( 'wp_footer', [ self::class, 'flush_to_footer' ], 999 );
		add_filter( 'jet-smart-filters/render/ajax/data', [ self::class, 'flush_to_ajax' ], 999 );

		// Consumer JS — needed for both flush channels.
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_consumer' ] );
	}

	/**
	 * Non-AJAX flush. Emits one inline <script> at the end of <body> so
	 * the consumer JS finds `window.JQBEBDebug` on document-ready.
	 */
	public static function flush_to_footer(): void {
		if ( wp_doing_ajax() || is_admin() ) {
			return;
		}
		if ( empty( self::$buffer ) ) {
			return;
		}
		printf(
			'<script>window.JQBEBDebug = %s;</script>',
			wp_json_encode( [ 'entries' => self::$buffer ] )
		);
	}

	/**
	 * AJAX flush. Hooked on `jet-smart-filters/render/ajax/data` (runs
	 * just before wp_send_json in JSF's ajax_apply_filters). Append the
	 * buffer under a key the consumer JS knows to look for.
	 *
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public static function flush_to_ajax( $args ) {
		if ( ! is_array( $args ) ) {
			return $args;
		}
		if ( empty( self::$buffer ) ) {
			return $args;
		}
		$args['_jqbeb_debug'] = [ 'entries' => self::$buffer ];
		return $args;
	}

	public static function enqueue_consumer(): void {
		wp_enqueue_script(
			'jqbeb-debug',
			JQBEB_URL . 'assets/js/debug.js',
			[],
			JQBEB_VERSION,
			true
		);
	}

	/**
	 * @param string    $sql
	 * @param \WP_Query $query
	 * @return string
	 */
	public static function on_posts_request( $sql, $query ) {
		if ( ! ( $query instanceof \WP_Query ) ) {
			return $sql;
		}
		if ( ! $query->get( '_jqbeb_je_query_id' ) ) {
			return $sql;
		}
		self::log( 'posts_request', [
			'je_query_id'    => $query->get( '_jqbeb_je_query_id' ),
			'paged_qv'       => $query->get( 'paged' ),
			'posts_per_page' => $query->get( 'posts_per_page' ),
			'jet_smart'      => $query->get( 'jet_smart_filters' ),
			'geo_query'      => $query->get( 'geo_query' ),
			'sql'            => $sql,
		] );
		return $sql;
	}

	/**
	 * Capture found_posts / max_num_pages AFTER the SQL ran. Hooked on
	 * `the_posts` (priority 999) so JSF's own props_handler at priority 10
	 * has already recorded found_posts on $query.
	 *
	 * @param array     $posts
	 * @param \WP_Query $query
	 * @return array
	 */
	public static function on_the_posts( $posts, $query ) {
		if ( ! ( $query instanceof \WP_Query ) ) {
			return $posts;
		}
		if ( ! $query->get( '_jqbeb_je_query_id' ) ) {
			return $posts;
		}
		self::log( 'the_posts', [
			'je_query_id'   => $query->get( '_jqbeb_je_query_id' ),
			'paged_qv'      => $query->get( 'paged' ),
			'found_posts'   => $query->found_posts,
			'max_num_pages' => $query->max_num_pages,
			'row_count'     => is_array( $posts ) ? count( $posts ) : -1,
		] );
		return $posts;
	}
}
