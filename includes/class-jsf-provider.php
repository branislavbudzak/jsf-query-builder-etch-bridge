<?php
/**
 * JSF Provider — exposes "Etch Loop" as a content provider for JetSmartFilters.
 *
 * @package JQBEB
 */

namespace JQBEB;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\Jet_Smart_Filters_Provider_Base' ) ) {
	return;
}

class JSF_Provider extends \Jet_Smart_Filters_Provider_Base {

	/**
	 * Per-query gate: each tagged WP_Query gets its JSF args merge once.
	 * Keyed by the full provider/query_id flag string ("etch-loop/{query_id}").
	 *
	 * @var array<string, true>
	 */
	private array $applied = [];

	public function get_id() {
		return 'etch-loop';
	}

	public function get_name() {
		return __( 'Etch Loop', 'jsf-query-builder-etch-bridge' );
	}

	public function get_wrapper_selector() {
		return '.jsf-etch-loop';
	}

	public function get_wrapper_action() {
		return 'insert';
	}

	public function id_prefix() {
		return '.jsf-etch-q-';
	}

	public function in_depth() {
		return false;
	}

	public function apply_filters_in_request() {
		add_action( 'pre_get_posts', [ $this, 'apply_jsf_to_tagged_query' ], 60 );
	}

	public function apply_jsf_to_tagged_query( \WP_Query $query ): void {
		if ( is_admin() ) {
			return;
		}

		$flag = (string) $query->get( 'jet_smart_filters' );
		if ( '' === $flag ) {
			return;
		}
		if ( strpos( $flag, 'etch-loop' ) !== 0 ) {
			return;
		}
		if ( isset( $this->applied[ $flag ] ) ) {
			return;
		}

		$this->applied[ $flag ] = true;
		$this->merge_jsf_into_query( $query );
	}

	/**
	 * AJAX handler for filter changes. Re-renders the page via loopback HTTP
	 * request, extracts the wrapper inner HTML, and emits it. Loopback is
	 * cached for 60 seconds to absorb rapid filter changes.
	 */
	public function ajax_get_content() {
		if ( ! function_exists( 'jet_smart_filters' ) ) {
			return;
		}

		$current  = jet_smart_filters()->query->get_current_provider();
		$query_id = is_array( $current ) && ! empty( $current['query_id'] ) ? $current['query_id'] : 'default';

		$referrer = wp_get_referer();
		if ( ! $referrer ) {
			echo '<!-- jqbeb: no referrer -->';
			return;
		}

		$forwarded = $_REQUEST;
		unset( $forwarded['action'] );

		if ( ! empty( $forwarded['query'] ) && is_array( $forwarded['query'] ) ) {
			foreach ( $forwarded['query'] as $k => $v ) {
				if ( ! isset( $forwarded[ $k ] ) ) {
					$forwarded[ $k ] = $v;
				}
			}
			unset( $forwarded['query'] );
		}

		unset( $forwarded['props'] );
		unset( $forwarded['page'] );

		if ( empty( $forwarded['jsf'] ) && ! empty( $forwarded['provider'] ) ) {
			$forwarded['jsf'] = $forwarded['provider'];
		}

		$page_value = 0;
		foreach ( [ 'jet_paged', 'paged', 'pagenum' ] as $page_key ) {
			if ( ! empty( $forwarded[ $page_key ] ) ) {
				$page_value = absint( $forwarded[ $page_key ] );
				break;
			}
		}
		if ( $page_value > 1 ) {
			if ( empty( $forwarded['pagination'] ) ) {
				$forwarded['pagination'] = $page_value;
			}
			if ( empty( $forwarded['jet_paged'] ) ) {
				$forwarded['jet_paged'] = $page_value;
			}
		}

		$base_url = strtok( $referrer, '?' );
		$url      = add_query_arg( $forwarded, $base_url );

		$loopback_cache_key = 'jqbeb_lb_' . md5( $url );
		$cached             = get_transient( $loopback_cache_key );
		if ( is_array( $cached ) && isset( $cached['inner'] ) ) {
			echo $cached['inner']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — inner HTML from trusted self-loopback
			$this->update_jsf_props(
				$query_id,
				$page_value,
				isset( $cached['props'] ) && is_array( $cached['props'] ) ? $cached['props'] : null
			);
			return;
		}

		$cookies = [];
		foreach ( $_COOKIE as $name => $value ) {
			$cookies[] = new \WP_Http_Cookie( [ 'name' => $name, 'value' => $value ] );
		}

		$response = wp_remote_get( $url, [
			'timeout'     => 15,
			'redirection' => 3,
			'sslverify'   => apply_filters( 'jqbeb_loopback_sslverify', false ),
			'cookies'     => $cookies,
			'headers'     => [
				'X-JQBEB-Loopback' => '1',
			],
		] );

		if ( is_wp_error( $response ) ) {
			echo '<!-- jqbeb loopback error: ' . esc_html( $response->get_error_message() ) . ' -->';
			return;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( ! $body ) {
			echo '<!-- jqbeb: empty body from loopback -->';
			return;
		}

		$loopback_props = $this->extract_loopback_props( $body, $query_id );

		$inner = $this->extract_wrapper_inner_html( $body, $query_id );
		if ( false === $inner ) {
			echo '<!-- jqbeb: wrapper not found in loopback HTML -->';
			return;
		}

		// Strip props comment markers from inner HTML before sending to client.
		$inner = preg_replace( '/<!--JQBEB-PROPS:[A-Za-z0-9+\/=]+-->/', '', $inner );

		set_transient( $loopback_cache_key, [
			'inner' => $inner,
			'props' => $loopback_props,
		], MINUTE_IN_SECONDS );

		echo $inner; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		$this->update_jsf_props( $query_id, $page_value, $loopback_props );
	}

	private function extract_loopback_props( string $html, string $query_id ): ?array {
		if ( ! preg_match_all( '/<!--JQBEB-PROPS:([A-Za-z0-9+\/=]+)-->/', $html, $matches ) ) {
			return null;
		}
		foreach ( $matches[1] as $encoded ) {
			$decoded = json_decode( (string) base64_decode( $encoded ), true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}
			if ( ( $decoded['query_id'] ?? '' ) !== $query_id ) {
				continue;
			}
			if ( ! empty( $decoded['props'] ) && is_array( $decoded['props'] ) ) {
				return $decoded['props'];
			}
		}
		return null;
	}

	private function update_jsf_props( string $query_id, int $page_value, ?array $loopback_props ): void {
		if ( ! function_exists( 'jet_smart_filters' ) ) {
			return;
		}
		$existing = jet_smart_filters()->query->get_query_props( 'etch-loop', $query_id );
		if ( ! is_array( $existing ) ) {
			$existing = [];
		}
		$existing['page'] = (string) ( $page_value > 1 ? $page_value : 0 );

		if ( is_array( $loopback_props ) ) {
			if ( isset( $loopback_props['found_posts'] ) ) {
				$existing['found_posts'] = (int) $loopback_props['found_posts'];
			}
			if ( isset( $loopback_props['max_num_pages'] ) ) {
				$existing['max_num_pages'] = (int) $loopback_props['max_num_pages'];
			}
		}

		jet_smart_filters()->query->set_props( 'etch-loop', $existing, $query_id );
	}

	private function extract_wrapper_inner_html( string $html, string $query_id ) {
		$prev_state = libxml_use_internal_errors( true );

		$doc = new \DOMDocument();
		$doc->loadHTML( '<?xml encoding="UTF-8"?>' . $html );

		libxml_clear_errors();
		libxml_use_internal_errors( $prev_state );

		$xpath = new \DOMXPath( $doc );

		if ( 'default' === $query_id ) {
			$expr = '//*[contains(concat(" ", normalize-space(@class), " "), " jsf-etch-loop ")]';
		} else {
			$q_class = 'jsf-etch-q-' . $query_id;
			$expr = sprintf(
				'//*[contains(concat(" ", normalize-space(@class), " "), " jsf-etch-loop ") and contains(concat(" ", normalize-space(@class), " "), " %s ")]',
				$q_class
			);
		}

		$nodes = $xpath->query( $expr );
		if ( ! $nodes || $nodes->length === 0 ) {
			return false;
		}

		$wrapper = $nodes->item( 0 );
		$inner   = '';
		foreach ( $wrapper->childNodes as $child ) {
			$inner .= $doc->saveHTML( $child );
		}

		return $inner;
	}

	private function merge_jsf_into_query( \WP_Query $query ): void {
		if ( ! function_exists( 'jet_smart_filters' ) ) {
			return;
		}

		$jet_paged = 0;
		foreach ( [ 'jet_paged', 'paged', 'pagenum' ] as $key ) {
			if ( ! empty( $_REQUEST[ $key ] ) ) {
				$jet_paged = absint( $_REQUEST[ $key ] );
				break;
			}
		}
		if ( $jet_paged > 1 ) {
			$query->set( 'paged', $jet_paged );
		}

		$jsf_args = jet_smart_filters()->query->get_query_args();
		if ( ! is_array( $jsf_args ) ) {
			return;
		}

		foreach ( $jsf_args as $key => $value ) {
			if ( in_array( $key, [ 'meta_query', 'tax_query', 'date_query' ], true ) ) {
				$existing = $query->get( $key );
				if ( ! is_array( $existing ) ) {
					$existing = [];
				}
				$query->set( $key, array_merge( $existing, (array) $value ) );
			} elseif ( $key === 'paged' ) {
				$query->set( 'paged', absint( $value ) );
			} else {
				$query->set( $key, $value );
			}
		}
	}
}
