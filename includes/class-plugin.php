<?php
/**
 * Plugin bootstrap. Loads bridges conditionally based on which dependencies
 * are active. Each bridge is fully independent — install only what you need.
 *
 * @package JQBEB
 */

namespace JQBEB;

defined( 'ABSPATH' ) || exit;

class Plugin {

	private static ?Plugin $instance = null;

	public ?JSF_Bridge $jsf_bridge = null;
	public ?JE_Query_Builder_Bridge $je_bridge = null;
	public ?Shortcode $shortcode = null;
	public ?Admin_Page $admin_page = null;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	private function boot(): void {
		load_plugin_textdomain(
			'jsf-query-builder-etch-bridge',
			false,
			dirname( JQBEB_BASENAME ) . '/languages'
		);

		// Admin page always loads — it shows live status of dependencies.
		require_once JQBEB_DIR . 'includes/class-admin-page.php';
		$this->admin_page = new Admin_Page();

		// Etch is required for either bridge to do anything useful.
		// Probe runs at init p20 (Etch registers etch/element on init).
		add_action( 'init', [ $this, 'maybe_load_bridges' ], 20 );

		// Activation/admin notice if Etch is missing.
		add_action( 'admin_notices', [ $this, 'maybe_show_etch_missing_notice' ] );
	}

	public function maybe_load_bridges(): void {
		if ( ! $this->is_etch_active() ) {
			return;
		}

		if ( $this->is_jsf_active() ) {
			require_once JQBEB_DIR . 'includes/class-jsf-bridge.php';
			$this->jsf_bridge = new JSF_Bridge();

			require_once JQBEB_DIR . 'includes/class-shortcode.php';
			$this->shortcode = new Shortcode();
		}

		if ( $this->is_je_query_builder_active() ) {
			require_once JQBEB_DIR . 'includes/class-je-query-builder-bridge.php';
			$this->je_bridge = new JE_Query_Builder_Bridge();
		}
	}

	public function is_etch_active(): bool {
		return \WP_Block_Type_Registry::get_instance()->is_registered( 'etch/element' );
	}

	public function is_jsf_active(): bool {
		return function_exists( 'jet_smart_filters' );
	}

	public function is_je_query_builder_active(): bool {
		return class_exists( '\Jet_Engine\Query_Builder\Manager' );
	}

	public function maybe_show_etch_missing_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( $this->is_etch_active() ) {
			return;
		}
		// Suppress on plugins screen if Etch isn't even installed —
		// admin already sees it as inactive in plugin list.
		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'JSF Query Builder Etch Bridge:', 'jsf-query-builder-etch-bridge' ),
			esc_html__( 'Etch is not active. Both bridges will silently no-op until Etch is enabled.', 'jsf-query-builder-etch-bridge' )
		);
	}
}
