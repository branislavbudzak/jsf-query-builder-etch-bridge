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
		// is_admin() is TRUE inside admin-ajax. The bridge's direct AJAX
		// render path runs render_block() from there, so the loop's
		// inner WP_Query fires pre_get_posts in admin-ajax context.
		// Bypass the gate when we're in an in-process render so paged /
		// filter args still get applied.
		if ( ! JSF_Bridge::$in_ajax_render && is_admin() ) {
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
			if ( Debug::pagination_enabled() ) {
				Debug::log( 'apply_jsf_to_tagged_query GATE_SKIP', [ 'flag' => $flag ] );
			}
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

		if ( Debug::pagination_enabled() ) {
			Debug::log( 'ajax_get_content ENTRY', [
				'query_id'              => $query_id,
				'request_paged'         => $_REQUEST['paged'] ?? null,
				'request_jet_paged'     => $_REQUEST['jet_paged'] ?? null,
				'request_pagination'    => $_REQUEST['pagination'] ?? null,
				'request_query_keys'    => isset( $_REQUEST['query'] ) && is_array( $_REQUEST['query'] )
					? array_keys( $_REQUEST['query'] )
					: null,
				'request_query_geo'     => $_REQUEST['query']['geo_query'] ?? null,
				'request_top_geo'       => $_REQUEST['geo_query'] ?? null,
				'request_defaults_keys' => isset( $_REQUEST['defaults'] ) && is_array( $_REQUEST['defaults'] )
					? array_keys( $_REQUEST['defaults'] )
					: null,
				'request_defaults_paged' => $_REQUEST['defaults']['paged'] ?? null,
			] );
		}

		$referrer = wp_get_referer();
		if ( ! $referrer ) {
			echo '<!-- jqbeb: no referrer -->';
			return;
		}
		// Reject any referrer not on our own site. wp_get_referer() reads
		// $_REQUEST['_wp_http_referer'] / $_SERVER['HTTP_REFERER'], both
		// attacker-controlled. Without this guard the loopback wp_remote_get
		// below would fetch arbitrary URLs (SSRF) AND forward $_COOKIE there.
		if ( ! self::is_same_origin( $referrer ) ) {
			echo '<!-- jqbeb: cross-origin referrer rejected -->';
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

		// ============================================================
		// FAST PATH — direct in-process block render.
		//
		// The bridge caches each rendered jsf-etch-loop wrapper block
		// tree in a transient keyed by URL path + query_id (see
		// JSF_Bridge::on_render_block). We retrieve the tree here and
		// render it via render_block() directly, skipping the full-page
		// HTTP loopback. JE listing-grid does the same thing, which is
		// why its AJAX feels instant compared to a full-page reload.
		//
		// All bridge hooks (pre_render_block, pre_get_posts, render_block)
		// bypass their wp_doing_ajax() / is_admin() early-returns via
		// the JSF_Bridge::$in_ajax_render flag, so the loop's inner
		// WP_Query gets re-tagged and JSF's provider hook re-applies
		// paged + filter args exactly as on initial render.
		//
		// On cache miss (transient expired, never rendered, or different
		// referrer) we fall through to the HTTP loopback below.
		// ============================================================
		$referrer_path    = wp_parse_url( $referrer, PHP_URL_PATH ) ?: '/';
		$direct_cache_key = JSF_Bridge::block_cache_key( $referrer_path, $query_id );
		$direct_cached    = get_transient( $direct_cache_key );

		if ( is_array( $direct_cached ) && ! empty( $direct_cached['block'] ) ) {

			$post_id   = (int) ( $direct_cached['post_id'] ?? 0 );
			$prev_post = $GLOBALS['post'] ?? null;

			if ( $post_id > 0 ) {
				$post_obj = get_post( $post_id );
				if ( $post_obj ) {
					$GLOBALS['post'] = $post_obj;
					setup_postdata( $post_obj );
				}
			}

			// Register our pre_get_posts p60 hook (apply_jsf_to_tagged_query).
			// Normally this is registered by JSF's apply_filters_from_request
			// path (called from parse_request on regular page loads + in the
			// HTTP loopback). The admin-ajax dispatch path bypasses that —
			// JSF jumps directly from ajax_apply_filters to ajax_get_content
			// without ever calling apply_filters_in_request, so without this
			// manual call the hook never fires and the loop's WP_Query keeps
			// paged=0 (page 1) regardless of the request's `paged` value.
			$this->apply_filters_in_request();

			JSF_Bridge::$in_ajax_render = true;
			try {
				$rendered = render_block( $direct_cached['block'] );
			} finally {
				JSF_Bridge::$in_ajax_render = false;
				if ( $post_id > 0 ) {
					wp_reset_postdata();
					$GLOBALS['post'] = $prev_post;
				}
			}

			$inner = $this->extract_wrapper_inner_html( $rendered, $query_id );
			if ( false === $inner ) {
				// Could not extract inner — fall through to HTTP loopback.
				delete_transient( $direct_cache_key );
			} else {
				// Strip any leftover props comment markers (defensive — the
				// emit branch in on_render_block is gated on is_loopback()
				// which is false in the direct path).
				$inner = preg_replace( '/<!--JQBEB-PROPS:[A-Za-z0-9+\/=]+-->/', '', $inner );
				$inner = $this->ensure_non_empty_inner( $inner );

				echo $inner; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

				// Props were populated by the inner WP_Query during render
				// (via JSF's the_posts filter). update_jsf_props with null
				// loopback_props just merges the new page value on top.
				$this->update_jsf_props( $query_id, $page_value, null );
				return;
			}
		}

		// Cache key isolates logged-in users from each other and from anonymous
		// visitors. Without `u=` in the key, role-/login-/membership-gated loop
		// content rendered for one user would be served to another for the 60s
		// TTL. Filter `jqbeb_loopback_cache_enabled` lets sites with anonymous
		// personalized content (cart, geo, A/B) disable caching entirely.
		$cache_user_id      = is_user_logged_in() ? get_current_user_id() : 0;
		$cache_enabled      = (bool) apply_filters( 'jqbeb_loopback_cache_enabled', true, $cache_user_id );
		$loopback_cache_key = 'jqbeb_lb_' . md5( $url . '|u=' . $cache_user_id );

		if ( $cache_enabled ) {
			$cached = get_transient( $loopback_cache_key );
			if ( is_array( $cached ) && isset( $cached['inner'] ) ) {
				echo $cached['inner']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — inner HTML from trusted self-loopback
				$this->update_jsf_props(
					$query_id,
					$page_value,
					isset( $cached['props'] ) && is_array( $cached['props'] ) ? $cached['props'] : null
				);
				return;
			}
		}

		$cookies = [];
		foreach ( $_COOKIE as $name => $value ) {
			$cookies[] = new \WP_Http_Cookie( [ 'name' => $name, 'value' => $value ] );
		}

		$response = wp_remote_get( $url, [
			'timeout'     => 15,
			// Loopback to ourselves never legitimately needs a redirect; not
			// following them blocks any open redirect (or 3rd-party redirect
			// reachable from home_url()) from ferrying cookies off-site.
			'redirection' => 0,
			// Default-secure. Local-dev override:
			//   add_filter( 'jqbeb_loopback_sslverify', '__return_false' );
			'sslverify'   => apply_filters( 'jqbeb_loopback_sslverify', true ),
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
		$inner = $this->ensure_non_empty_inner( $inner );

		if ( $cache_enabled ) {
			set_transient( $loopback_cache_key, [
				'inner' => $inner,
				'props' => $loopback_props,
			], MINUTE_IN_SECONDS );
		}

		echo $inner; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		$this->update_jsf_props( $query_id, $page_value, $loopback_props );
	}

	/**
	 * Guarantee a non-empty payload for JSF's AJAX `content` field.
	 *
	 * When the filtered loop yields zero posts, Etch renders the wrapper
	 * with no children — `extract_wrapper_inner_html` correctly returns an
	 * empty string. JSF's frontend then receives `{ "content": "" }` and,
	 * defensively, leaves the existing wrapper DOM untouched (interpreting
	 * empty as "no update"), so the user keeps seeing the previous page's
	 * cards even though `pagination.found_posts === 0`.
	 *
	 * Emit a tiny sentinel HTML comment when the inner is effectively
	 * empty so JSF's replace path runs and the wrapper visibly clears.
	 * The comment is invisible in DOM and stable across browsers; sites
	 * that want a styled "no results" message can listen for the JSF
	 * `jet/smart-filters/content-rendered` event (or check the wrapper's
	 * own emptiness) and inject their own UI.
	 *
	 * Filterable via `jqbeb_empty_results_payload` so site code can
	 * substitute a richer empty-state placeholder.
	 */
	private function ensure_non_empty_inner( string $inner ): string {
		// Strip whitespace + leftover comments to detect "effectively empty".
		$probe = preg_replace( '/<!--.*?-->/s', '', $inner );
		$probe = trim( (string) $probe );
		if ( $probe !== '' ) {
			return $inner;
		}
		$payload = (string) apply_filters(
			'jqbeb_empty_results_payload',
			'<!--jqbeb:empty-results-->',
			$inner
		);
		return $payload !== '' ? $payload : '<!--jqbeb:empty-results-->';
	}

	/**
	 * True iff $url has the same scheme + host as home_url() or site_url().
	 * Used to gate the loopback wp_remote_get against SSRF via spoofed
	 * Referer / _wp_http_referer.
	 */
	private static function is_same_origin( string $url ): bool {
		$ref = wp_parse_url( $url );
		if ( empty( $ref['host'] ) ) {
			return false;
		}
		foreach ( [ home_url(), site_url() ] as $known ) {
			$cmp = wp_parse_url( $known );
			if ( empty( $cmp['host'] ) ) {
				continue;
			}
			if ( strcasecmp( $ref['host'], $cmp['host'] ) !== 0 ) {
				continue;
			}
			// Require scheme match when both sides declare one — blocks
			// https → http downgrade via referer spoof. If either side has
			// no scheme, accept on host match alone.
			if ( ! empty( $ref['scheme'] ) && ! empty( $cmp['scheme'] )
				&& strcasecmp( $ref['scheme'], $cmp['scheme'] ) !== 0 ) {
				continue;
			}
			return true;
		}
		return false;
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

		if ( Debug::pagination_enabled() ) {
			Debug::log( 'merge_jsf_into_query JSF_ARGS', [
				'jet_paged_from_request' => $jet_paged,
				'jsf_paged'              => $jsf_args['paged'] ?? null,
				'jsf_geo_query'          => $jsf_args['geo_query'] ?? null,
				'jsf_keys'               => array_keys( $jsf_args ),
				'query_paged_before'     => $query->get( 'paged' ),
				'query_pp_before'        => $query->get( 'posts_per_page' ),
			] );
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

		if ( Debug::pagination_enabled() ) {
			Debug::log( 'merge_jsf_into_query AFTER', [
				'query_paged'     => $query->get( 'paged' ),
				'query_pp'        => $query->get( 'posts_per_page' ),
				'query_geo_query' => $query->get( 'geo_query' ),
				'query_orderby'   => $query->get( 'orderby' ),
			] );
		}
	}
}
