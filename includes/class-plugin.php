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

		// Admin page always loads — it shows live dependency status.
		require_once JQBEB_DIR . 'includes/class-admin-page.php';
		$this->admin_page = new Admin_Page();

		// JSF bridge MUST instantiate at plugins_loaded so it can register
		// its 'jet-smart-filters/providers/register' listener before that
		// action fires at init priority -998.
		// At plugins_loaded all plugin main files have been included, so
		// JSF/JE class definitions are available regardless of load order.
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

		add_action( 'admin_notices', [ $this, 'maybe_show_etch_missing_notice' ] );
	}

	public function is_etch_active(): bool {
		// Etch registers etch/element on init p10, so before init this can
		// return false. The admin page is rendered after init, so this is
		// reliable in admin context.
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
		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'JSF Query Builder Etch Bridge:', 'jsf-query-builder-etch-bridge' ),
			esc_html__( 'Etch is not active. Both bridges will silently no-op until Etch is enabled.', 'jsf-query-builder-etch-bridge' )
		);
	}
}
