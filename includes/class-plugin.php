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

		// Toggleable pagination diagnostics. No-op unless
		// JQBEB_DEBUG_PAGINATION is defined truthy in wp-config.php.
		require_once JQBEB_DIR . 'includes/class-debug.php';
		Debug::register_sql_listener();

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

		// JE bridge MUST defer to init p0. JetEngine registers
		// \Jet_Engine\Query_Builder\Manager via its components-manager on
		// init priority -1, so at plugins_loaded the class doesn't exist yet
		// and is_je_query_builder_active() would return false. The bridge's
		// own hooks (pre_render_block, pre_get_posts, pre_user_query,
		// pre_get_terms) all fire well after init, so init p0 registration
		// is safe.
		add_action( 'init', [ $this, 'maybe_boot_je_bridge' ], 0 );

		add_action( 'admin_notices', [ $this, 'maybe_show_etch_missing_notice' ] );
	}

	public function maybe_boot_je_bridge(): void {
		if ( ! $this->is_je_query_builder_active() ) {
			return;
		}
		require_once JQBEB_DIR . 'includes/class-je-query-builder-bridge.php';
		$this->je_bridge = new JE_Query_Builder_Bridge();
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
