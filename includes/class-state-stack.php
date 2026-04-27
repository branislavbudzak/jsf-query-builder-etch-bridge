<?php
/**
 * Per-request state stack used by both bridges to track which Etch wrapper
 * is currently rendering. Push on pre_render_block, pop on pre_get_posts
 * (or render_block as a safety-net).
 *
 * @package JQBEB
 */

namespace JQBEB;

defined( 'ABSPATH' ) || exit;

class State_Stack {

	/**
	 * @var string[]
	 */
	private array $stack = [];

	public function push( string $value ): void {
		$this->stack[] = $value;
	}

	public function current(): ?string {
		if ( empty( $this->stack ) ) {
			return null;
		}
		return end( $this->stack );
	}

	public function pop(): ?string {
		return array_pop( $this->stack );
	}

	public function clear(): void {
		$this->stack = [];
	}

	public function is_empty(): bool {
		return empty( $this->stack );
	}
}
