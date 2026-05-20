<?php
/**
 * JetEngine loop-context bridge for Etch loops.
 *
 * Makes JE blocks that resolve their post via `jet_engine()->listings->data
 * ->get_current_object()` work correctly when rendered inside an Etch loop.
 *
 * The problem
 * -----------
 * JE Listing Grid runs WP_Query in the standard way, which fires `the_post`
 * for each iteration. JE's data manager listens on `the_post` and calls
 * `set_current_object($post)`, so any nested JE block that reads
 * `get_current_object()` (e.g. Data Store Button → renders `data-post` /
 * `data-args.post_id`) sees the correct loop item.
 *
 * Etch loops do NOT call `setup_postdata()` and do NOT fire `the_post`. They
 * push the current item onto Etch's `DynamicContextProvider` stack instead.
 * JE never sees the loop iteration, so `current_object` stays whatever the
 * outer page resolved to → the Data Store Button operates on the page ID
 * instead of the card.
 *
 * The fix
 * -------
 * For block names in our supported list, sync JE's `current_object` to the
 * topmost 'loop' entry on Etch's stack before the block renders, then restore
 * the previous value after. Per-block scope (default: only
 * `jet-engine/data-store-button`) keeps the blast radius small — other JE
 * dynamic blocks already work via Etch's own dynamic-data resolution and we
 * don't want to step on them.
 *
 * Supported scenarios
 * -------------------
 * - Plain Etch loop (Etch's own posts query). Works.
 * - Etch loop driven by JE_Query_Builder_Bridge (any JE query type that
 *   produces WP_Post / WP_User items). Works for the same reason — the bridge
 *   only mutates the WP_*_Query args, the loop item Etch pushes is still a
 *   WP_Post / WP_User.
 * - JSF AJAX (in-process render). Hooks fire normally during the in-process
 *   `render_block()` call, so the AJAX-rendered button HTML carries the
 *   correct `data-post`.
 *
 * @package JQBEB
 */

namespace JQBEB;

defined( 'ABSPATH' ) || exit;

class JE_Loop_Context_Bridge {

	/**
	 * Block names whose render is wrapped with a JE current_object sync.
	 *
	 * Filterable via `jqbeb_loop_context_block_names` so users can extend the
	 * list (e.g. third-party JE add-ons that share the same context-resolution
	 * pattern).
	 *
	 * @var array<int, string>
	 */
	private array $target_block_names;

	/**
	 * Stack of previously-set JE current_object values. One entry per
	 * `pre_render_block` invocation that we acted on, popped on the matching
	 * `render_block`. Stack-shaped so nested supported blocks (e.g. button
	 * inside another supported block) restore in correct order.
	 *
	 * @var array<int, mixed>
	 */
	private array $stash_stack = array();

	public function __construct() {
		$this->target_block_names = apply_filters(
			'jqbeb_loop_context_block_names',
			array( 'jet-engine/data-store-button' )
		);

		add_filter( 'pre_render_block', array( $this, 'sync_je_current_object' ), 5, 2 );
		add_filter( 'render_block', array( $this, 'restore_je_current_object' ), 5, 2 );
	}

	/**
	 * Before a target block renders, peek Etch's loop stack and swap JE's
	 * `current_object` to the loop item if one is present.
	 *
	 * Always returns $pre unchanged — the filter is used purely for its side
	 * effect.
	 *
	 * @param string|null $pre   Short-circuit content (not used).
	 * @param array       $block Parsed block array.
	 * @return string|null
	 */
	public function sync_je_current_object( $pre, $block ) {
		if ( ! is_array( $block ) ) {
			return $pre;
		}
		$name = $block['blockName'] ?? '';
		if ( '' === $name || ! in_array( $name, $this->target_block_names, true ) ) {
			return $pre;
		}
		if ( ! function_exists( 'jet_engine' ) ) {
			return $pre;
		}
		if ( ! class_exists( '\Etch\Blocks\Global\DynamicContent\DynamicContextProvider' ) ) {
			return $pre;
		}

		$loop_item = $this->find_etch_loop_item();
		if ( null === $loop_item ) {
			return $pre;
		}

		// Stash before mutating so restore_je_current_object can roll back.
		$this->stash_stack[] = jet_engine()->listings->data->get_current_object();
		jet_engine()->listings->data->set_current_object( $loop_item );

		return $pre;
	}

	/**
	 * After a target block renders, restore the previous JE `current_object`
	 * if we stashed one in `sync_je_current_object`.
	 *
	 * @param string $html  Rendered block HTML.
	 * @param array  $block Parsed block array.
	 * @return string
	 */
	public function restore_je_current_object( $html, $block ) {
		if ( ! is_array( $block ) ) {
			return $html;
		}
		$name = $block['blockName'] ?? '';
		if ( '' === $name || ! in_array( $name, $this->target_block_names, true ) ) {
			return $html;
		}
		if ( empty( $this->stash_stack ) ) {
			return $html;
		}
		if ( ! function_exists( 'jet_engine' ) ) {
			return $html;
		}

		$previous = array_pop( $this->stash_stack );
		jet_engine()->listings->data->set_current_object( $previous );
		return $html;
	}

	/**
	 * Walk Etch's DynamicContextProvider stack from top, return the source of
	 * the topmost 'loop' entry resolved to a WP_Post / WP_User / WP_Term.
	 *
	 * Etch's loop handlers do NOT push raw WP_Post / WP_User / WP_Term
	 * instances onto the stack — they push the OUTPUT of `get_dynamic_data()`
	 * (`WpQueryLoopHandler::get_loop_data` → `get_dynamic_data($post)`), which
	 * is an associative array of post properties merged with `get_object_vars`.
	 * So this method has to detect the array shape and resolve back to an
	 * object via `get_post()` / `get_user_by()` / `get_term()`.
	 *
	 * Nested Etch loops: walking from top first picks the innermost loop,
	 * which is the correct semantic — that's the loop the block is "in".
	 *
	 * @return \WP_Post|\WP_User|\WP_Term|null
	 */
	private function find_etch_loop_item() {
		$stack   = \Etch\Blocks\Global\DynamicContent\DynamicContextProvider::get_stack();
		$entries = $stack->all();

		for ( $i = count( $entries ) - 1; $i >= 0; $i-- ) {
			$entry = $entries[ $i ];

			if ( ! ( $entry instanceof \Etch\Blocks\Global\DynamicContent\DynamicContentEntry ) ) {
				continue;
			}
			if ( 'loop' !== $entry->get_type() ) {
				continue;
			}

			return $this->resolve_loop_source( $entry->get_source() );
		}

		return null;
	}

	/**
	 * Resolve a loop entry's source value to a WP_Post / WP_User / WP_Term.
	 *
	 * Handles all of Etch's loop-data shapes:
	 *   - direct WP_Post / WP_User / WP_Term instance (defensive — current
	 *     Etch versions don't push these, but a future Etch release or a
	 *     third-party loop handler might)
	 *   - associative array from `get_dynamic_data()` (Etch's default; shape
	 *     determined by `get_base_post_data` / `get_base_user_data` /
	 *     `get_base_term_data` in `Etch\Traits\DynamicDataBases`)
	 *   - bare numeric ID (assume post)
	 *
	 * @param mixed $source The loop entry's source value.
	 * @return \WP_Post|\WP_User|\WP_Term|null
	 */
	private function resolve_loop_source( $source ) {
		if ( $source instanceof \WP_Post || $source instanceof \WP_User || $source instanceof \WP_Term ) {
			return $source;
		}

		if ( is_numeric( $source ) ) {
			$post = get_post( (int) $source );
			return $post instanceof \WP_Post ? $post : null;
		}

		if ( ! is_array( $source ) ) {
			return null;
		}

		// Etch's get_base_post_data merges via wp_parse_args($data, get_object_vars($post)),
		// so the array carries both 'id' (lowercase, Etch's own normalised key) and 'ID'
		// (uppercase, from WP_Post::$ID). Same pattern for users/terms.
		$id = $source['ID'] ?? $source['id'] ?? $source['term_id'] ?? null;
		if ( ! is_numeric( $id ) ) {
			return null;
		}
		$id = (int) $id;

		// Discriminate post vs user vs term by shape. Each base-data shape
		// carries unique WP_*_property markers that the others don't:
		//   - WP_Post  → post_type / post_status / post_author
		//   - WP_User  → user_login / user_email
		//   - WP_Term  → taxonomy / term_taxonomy_id
		if ( isset( $source['post_type'] ) || isset( $source['post_status'] ) || isset( $source['post_author'] ) ) {
			$post = get_post( $id );
			return $post instanceof \WP_Post ? $post : null;
		}

		if ( isset( $source['user_login'] ) || isset( $source['user_email'] ) ) {
			$user = get_user_by( 'id', $id );
			return $user instanceof \WP_User ? $user : null;
		}

		if ( isset( $source['taxonomy'] ) || isset( $source['term_taxonomy_id'] ) ) {
			$term = get_term( $id );
			return $term instanceof \WP_Term ? $term : null;
		}

		// No discriminating marker. Default to post — covers the common case
		// of a custom loop handler that only emits an ID-like shape.
		$post = get_post( $id );
		return $post instanceof \WP_Post ? $post : null;
	}
}
