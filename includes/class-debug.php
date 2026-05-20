<?php
/**
 * Toggleable debug logging for pagination diagnosis.
 *
 * Off by default — enable per-site with:
 *
 *   define( 'JQBEB_DEBUG_PAGINATION', true );
 *
 * in wp-config.php. Combine with WP_DEBUG_LOG=true so output lands in
 * wp-content/debug.log.
 *
 * Used to diagnose the JSF Location & Distance + pagination interaction
 * documented in CHANGELOG entry for v1.1.1.
 *
 * @package JQBEB
 */

namespace JQBEB;

defined( 'ABSPATH' ) || exit;

class Debug {

	public static function pagination_enabled(): bool {
		return defined( 'JQBEB_DEBUG_PAGINATION' ) && JQBEB_DEBUG_PAGINATION;
	}

	/**
	 * Single log line, prefixed so `grep '[jqbeb-debug]' debug.log` finds them.
	 *
	 * @param string              $label Short tag for the log site.
	 * @param array<string,mixed> $data  Anything JSON-encodable.
	 */
	public static function log( string $label, array $data ): void {
		if ( ! self::pagination_enabled() ) {
			return;
		}
		error_log( '[jqbeb-debug] ' . $label . ': ' . wp_json_encode( $data ) );
	}

	/**
	 * Register the `posts_request` listener that captures the final SQL of
	 * any WP_Query we tagged with `_jqbeb_je_query_id`.
	 *
	 * Hooked once from Plugin::boot() when pagination debugging is on. Stays
	 * a no-op (early-returns) otherwise so the hook overhead is minimal.
	 */
	public static function register_sql_listener(): void {
		if ( ! self::pagination_enabled() ) {
			return;
		}
		add_filter( 'posts_request', [ self::class, 'on_posts_request' ], 999, 2 );
		add_filter( 'the_posts', [ self::class, 'on_the_posts' ], 999, 2 );
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
