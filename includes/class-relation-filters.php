<?php
/**
 * JetEngine relation filters for the `etch-loop` JSF provider.
 *
 * JetEngine turns `related_children*<id>` / `related_parents*<id>` meta
 * clauses into `post__in` at `jet-smart-filters/query/final-query` p-10.
 * The bridge's client whitelist (p PHP_INT_MAX) drops every `post__in`,
 * because the browser can send one too, so the relation restriction was
 * lost and its option counts were read from `wp_postmeta`.
 *
 * Instead the bridge takes the relation clauses out of the meta_query
 * BEFORE JetEngine sees them (p-20) and keeps only a plan under
 * `self::ARG`. The plan is resolved server-side in
 * `JSF_Bridge::merge_filter_args()`, the one path results, indexer counts
 * and dynamic range all go through, and intersected with the trusted
 * baseline. It can only narrow the result: anything invalid resolves to
 * `post__in = [0]`, never to "no restriction".
 *
 * @package JQBEB
 */

namespace JQBEB;

defined( 'ABSPATH' ) || exit;

class Relation_Filters {

	/** Query arg carrying the extracted relation plan. Never trusted from input. */
	public const ARG = 'jqbeb_relation_filters';

	private const KEY_PATTERN = '/^related_(children|parents)\*(\d+)$/';

	/**
	 * Move relation clauses out of `meta_query` into a plan under self::ARG.
	 *
	 * Top-level clauses are AND-ed. A nested group (JSF builds one per
	 * multi-value or multi-key filter) keeps its own OR / AND. A group that
	 * mixes relation and plain meta clauses under OR cannot be expressed as
	 * an ID restriction, so it is marked invalid (empty result).
	 */
	public static function extract( array $args ): array {
		unset( $args[ self::ARG ] );

		if ( empty( $args['meta_query'] ) || ! is_array( $args['meta_query'] ) ) {
			return $args;
		}

		$groups = [];

		foreach ( $args['meta_query'] as $key => $clause ) {
			if ( ! is_array( $clause ) ) {
				continue;
			}

			if ( self::is_relation_key( $clause['key'] ?? null ) ) {
				$groups[] = [ 'relation' => 'AND', 'clauses' => [ self::parse_clause( $clause ) ] ];
				unset( $args['meta_query'][ $key ] );
				continue;
			}

			if ( isset( $clause['key'] ) ) {
				continue;
			}

			$relation_clauses = [];
			$has_plain        = false;
			foreach ( $clause as $inner_key => $inner ) {
				if ( ! is_array( $inner ) ) {
					continue;
				}
				if ( self::is_relation_key( $inner['key'] ?? null ) ) {
					$relation_clauses[] = self::parse_clause( $inner );
					unset( $clause[ $inner_key ] );
				} elseif ( ! isset( $inner['key'] ) && self::contains_relation_key( $inner ) ) {
					// Deeper nesting (multi-key + custom checkbox): not expressible, fail closed.
					$relation_clauses[] = [ 'invalid' => 'nesting' ];
					unset( $clause[ $inner_key ] );
				} else {
					$has_plain = true;
				}
			}

			if ( ! $relation_clauses ) {
				continue;
			}

			$relation = strtoupper( (string) ( $clause['relation'] ?? 'AND' ) );
			if ( $has_plain && 'OR' === $relation ) {
				$relation_clauses = [ [ 'invalid' => 'mixed OR group' ] ];
			}

			$groups[] = [ 'relation' => 'OR' === $relation ? 'OR' : 'AND', 'clauses' => $relation_clauses ];

			if ( $has_plain ) {
				$args['meta_query'][ $key ] = $clause;
			} else {
				unset( $args['meta_query'][ $key ] );
			}
		}

		if ( $groups ) {
			$args[ self::ARG ] = $groups;
		}

		$rest = array_diff_key( $args['meta_query'], [ 'relation' => true ] );
		if ( ! $rest ) {
			unset( $args['meta_query'] );
		}

		return $args;
	}

	/**
	 * Apply a plan to server args: intersect with the baseline `post__in`.
	 *
	 * @param array $base  Trusted server args (loop query vars or defaults).
	 * @param mixed $plan  Value of self::ARG.
	 */
	public static function apply( array $base, $plan ): array {
		$ids = self::resolve( $plan, $base['post_type'] ?? '' );

		if ( null !== $ids ) {
			// Baseline first, so its order survives for `orderby => post__in`.
			$scope = $base['post__in'] ?? [];
			if ( is_array( $scope ) && $scope ) {
				$ids = array_values( array_intersect( array_map( 'intval', $scope ), $ids ) );
			}

			// WP_Query ignores post__not_in once post__in is set, so apply it here.
			$exclude = $base['post__not_in'] ?? [];
			if ( is_array( $exclude ) && $exclude ) {
				$ids = array_values( array_diff( $ids, array_map( 'intval', $exclude ) ) );
			}
		}

		$base['post__in'] = $ids ? $ids : [ 0 ];
		return $base;
	}

	/**
	 * Resolve a plan to the allowed post IDs, or null on any invalid input.
	 *
	 * @param mixed        $plan
	 * @param string|array $post_type Loop post type(s); the related object must match.
	 * @return int[]|null
	 */
	public static function resolve( $plan, $post_type ): ?array {
		if ( ! is_array( $plan ) || ! $plan || ! self::relations_available() ) {
			return null;
		}

		$result = null;

		foreach ( $plan as $group ) {
			$clauses = is_array( $group ) ? ( $group['clauses'] ?? null ) : null;
			if ( ! is_array( $clauses ) || ! $clauses ) {
				return null;
			}

			$group_ids = null;
			foreach ( $clauses as $clause ) {
				$ids = self::resolve_clause( $clause, $post_type );
				if ( null === $ids ) {
					return null;
				}
				if ( null === $group_ids ) {
					$group_ids = $ids;
				} elseif ( 'OR' === ( $group['relation'] ?? 'AND' ) ) {
					$group_ids = array_values( array_unique( array_merge( $group_ids, $ids ) ) );
				} else {
					$group_ids = array_values( array_intersect( $group_ids, $ids ) );
				}
			}

			$result = null === $result ? $group_ids : array_values( array_intersect( $result, $group_ids ) );
		}

		return $result;
	}

	/**
	 * Per-option counts for a relation filter, limited to the matching IDs.
	 *
	 * @param string $key          `related_children*<id>` or `related_parents*<id>`.
	 * @param int[]  $matching_ids Posts matching the current filtered query.
	 * @param mixed        $options      Option values the filter displays.
	 * @param string|array $post_type    Loop post type(s); the counted side must match.
	 * @return array<string,int>|null Null when the key is not a usable relation.
	 */
	public static function counts( string $key, array $matching_ids, $options, $post_type ): ?array {
		if ( ! preg_match( self::KEY_PATTERN, $key, $m ) || ! self::relations_available() ) {
			return null;
		}

		$relation = jet_engine()->relations->get_active_relations( (int) $m[2] );
		if ( ! $relation || empty( $relation->db ) ) {
			return null;
		}

		$counted = (string) $relation->get_args( 'children' === $m[1] ? 'child_object' : 'parent_object' );
		if ( ! self::object_matches_post_type( $counted, $post_type ) ) {
			return null;
		}

		// JSF's indexer passes the option values as a list (post IDs here).
		$bucket = [];
		foreach ( (array) $options as $value ) {
			if ( is_scalar( $value ) && '' !== (string) $value ) {
				$bucket[ (string) $value ] = 0;
			}
		}

		$ids = array_values( array_filter( array_map( 'intval', $matching_ids ) ) );
		if ( ! $ids ) {
			return $bucket;
		}

		// children filter: options are parents, counted items are children.
		[ $option_col, $item_col ] = 'children' === $m[1]
			? [ 'parent_object_id', 'child_object_id' ]
			: [ 'child_object_id', 'parent_object_id' ];

		$rel_id = (int) $relation->get_id();
		$ids_in = implode( ',', $ids );
		$rows   = $relation->db->raw_query(
			"SELECT {$option_col} AS id, COUNT(DISTINCT {$item_col}) AS cnt FROM %table%
			 WHERE rel_id = {$rel_id} AND {$item_col} IN ({$ids_in})
			 GROUP BY {$option_col}"
		);

		foreach ( (array) $rows as $row ) {
			$bucket[ (string) absint( $row->id ) ] = (int) $row->cnt;
		}

		return $bucket;
	}

	/** JetEngine can run with its relations component unregistered. */
	private static function relations_available(): bool {
		return function_exists( 'jet_engine' ) && ! empty( jet_engine()->relations );
	}

	private static function contains_relation_key( array $group ): bool {
		foreach ( $group as $item ) {
			if ( is_array( $item ) && ( self::is_relation_key( $item['key'] ?? null ) || self::contains_relation_key( $item ) ) ) {
				return true;
			}
		}
		return false;
	}

	/** Same detection JetEngine uses, so nothing it would translate slips through. */
	public static function is_relation_key( $key ): bool {
		return is_string( $key )
			&& ( false !== strpos( $key, 'related_children' ) || false !== strpos( $key, 'related_parents' ) );
	}

	private static function parse_clause( array $clause ): array {
		if ( ! preg_match( self::KEY_PATTERN, (string) $clause['key'], $m ) ) {
			return [ 'invalid' => 'key' ];
		}

		$compare = strtoupper( (string) ( $clause['compare'] ?? '=' ) );
		if ( ! in_array( $compare, [ '=', 'IN' ], true ) ) {
			return [ 'invalid' => 'compare' ];
		}

		$values = is_array( $clause['value'] ?? null ) ? $clause['value'] : [ $clause['value'] ?? '' ];
		$ids    = [];
		foreach ( $values as $value ) {
			// Strictly positive integers only. JetEngine treats 0 / '' as
			// "no parent condition", which would widen the result.
			if ( ! is_scalar( $value ) || ! preg_match( '/^[1-9]\d*$/', trim( (string) $value ) ) ) {
				return [ 'invalid' => 'value' ];
			}
			$ids[] = (int) $value;
		}

		if ( ! $ids ) {
			return [ 'invalid' => 'value' ];
		}

		return [
			'direction' => $m[1],
			'rel_id'    => (int) $m[2],
			'ids'       => array_values( array_unique( $ids ) ),
		];
	}

	/** @return int[]|null */
	private static function resolve_clause( $clause, $post_type ): ?array {
		if ( ! is_array( $clause ) || isset( $clause['invalid'] ) || empty( $clause['rel_id'] ) || empty( $clause['ids'] ) ) {
			return null;
		}

		foreach ( (array) $clause['ids'] as $id ) {
			if ( ! is_int( $id ) || $id < 1 ) {
				return null;
			}
		}

		$relation = jet_engine()->relations->get_active_relations( (int) $clause['rel_id'] );
		if ( ! $relation ) {
			return null;
		}

		$children = 'children' === ( $clause['direction'] ?? '' );
		if ( ! $children && 'parents' !== ( $clause['direction'] ?? '' ) ) {
			return null;
		}

		// The filtered items are the loop's posts, so they must be the side
		// of the relation that the direction returns.
		$object = (string) $relation->get_args( $children ? 'child_object' : 'parent_object' );
		if ( ! self::object_matches_post_type( $object, $post_type ) ) {
			return null;
		}

		$ids = $children
			? $relation->get_children( $clause['ids'], 'ids' )
			: $relation->get_parents( $clause['ids'], 'ids' );

		return array_values( array_unique( array_filter( array_map( 'intval', (array) $ids ) ) ) );
	}

	private static function object_matches_post_type( string $object, $post_type ): bool {
		$parts = explode( '::', $object, 2 );
		if ( 'posts' !== $parts[0] || empty( $parts[1] ) ) {
			return false;
		}

		$types = array_filter( (array) $post_type, 'is_string' );
		if ( ! $types || in_array( 'any', $types, true ) ) {
			return true;
		}

		return in_array( $parts[1], $types, true );
	}
}
