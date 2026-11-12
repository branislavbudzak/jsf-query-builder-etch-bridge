<?php
/**
 * Output-buffer guard around JetSmartFilters blocks.
 *
 * The problem
 * -----------
 * JSF (3.8.4 to 3.8.6.3 at least, `includes/blocks/base.php`, block
 * `render()`) opens an output buffer, prints the filter's wrapper
 * `<div class="jet-smart-filters-… jet-filter …">`, renders the options into
 * a second buffer and, when that markup is empty (a filter with no options,
 * e.g. a taxonomy whose terms have no posts), returns '' WITHOUT closing the
 * first buffer. In a block theme (Etch) the template is rendered before
 * `<!DOCTYPE html>` is printed, so the whole page then lands inside the
 * orphaned buffer: the response starts with the unclosed filter `<div>` and
 * the browser nests header, content and footer inside a filter that JSF hides
 * (`data-indexer-rule="hide"`). Measured on the Poctivá poživeň staging
 * 2026-11-12: every product archive was blank.
 *
 * The fix
 * -------
 * Note `ob_get_level()` before each JSF block really renders (`pre_render_block`
 * last, only when nobody short-circuited) and, on `render_block` (first),
 * discard every removable buffer the block left open. What is discarded is
 * only the empty filter's wrapper, which JSF meant to drop anyway. A stack
 * keeps nested blocks apart.
 *
 * Coexistence with an equivalent guard (poziven-core-logic module
 * `filter-guard`): both note the same level (nothing opens a buffer between
 * two PHP_INT_MAX callbacks), the first `render_block` callback closes down to
 * it, the second finds `ob_get_level()` already equal and does nothing.
 *
 * Opt out: `add_filter( 'jqbeb_jsf_buffer_guard_enabled', '__return_false' )`
 * before `plugins_loaded` (mu-plugin or another plugin's main file).
 *
 * @package JQBEB
 */

namespace JQBEB;

defined( 'ABSPATH' ) || exit;

class JSF_Buffer_Guard {

	private const BLOCK_PREFIX = 'jet-smart-filters/';

	/** @var array<int, int> Buffer levels noted before each rendering JSF block. */
	private array $levels = [];

	public function __construct() {
		// Last on pre_render_block: a later short-circuit would skip render_block and leave a level on the stack.
		add_filter( 'pre_render_block', [ $this, 'remember_buffer_level' ], PHP_INT_MAX, 2 );
		// First on render_block: the leaked buffer is closed before anyone else reads the block output.
		add_filter( 'render_block', [ $this, 'close_leaked_buffers' ], PHP_INT_MIN, 2 );
	}

	/**
	 * `pre_render_block`: note the level when the JSF block will really render.
	 *
	 * @param mixed $pre   Short-circuit value, null when the block renders.
	 * @param mixed $block Parsed block.
	 * @return mixed Unchanged $pre.
	 */
	public function remember_buffer_level( $pre, $block ) {
		if ( null === $pre && self::is_filter_block( $block ) ) {
			$this->levels[] = ob_get_level();
		}
		return $pre;
	}

	/**
	 * `render_block`: discard every buffer the JSF block opened and did not close.
	 *
	 * @param mixed $content Rendered block content.
	 * @param mixed $block   Parsed block.
	 * @return mixed Unchanged $content.
	 */
	public function close_leaked_buffers( $content, $block ) {
		if ( [] === $this->levels || ! self::is_filter_block( $block ) ) {
			return $content;
		}
		$level = array_pop( $this->levels );
		while ( ob_get_level() > $level && self::top_buffer_removable() ) {
			ob_end_clean();
		}
		return $content;
	}

	/** @param mixed $block Parsed block. */
	private static function is_filter_block( $block ): bool {
		return is_array( $block )
			&& is_string( $block['blockName'] ?? null )
			&& str_starts_with( $block['blockName'], self::BLOCK_PREFIX );
	}

	/** A buffer PHP cannot remove (an extension's handler) stays; ob_end_clean() would only raise a notice. */
	private static function top_buffer_removable(): bool {
		$status = ob_get_status();
		return 0 !== ( (int) ( $status['flags'] ?? 0 ) & PHP_OUTPUT_HANDLER_REMOVABLE );
	}
}
