<?php
/**
 * Plugin Name:       JSF Query Builder Etch Bridge
 * Plugin URI:        https://github.com/branobudzak/jsf-query-builder-etch-bridge
 * Description:       Drive Etch native Query Loops with JetSmartFilters and/or JetEngine Query Builder. Each bridge works independently — install only what you need.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Branislav Budzák
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       jsf-query-builder-etch-bridge
 *
 * @package JQBEB
 */

defined( 'ABSPATH' ) || exit;

define( 'JQBEB_VERSION', '0.1.0' );
define( 'JQBEB_FILE', __FILE__ );
define( 'JQBEB_DIR', plugin_dir_path( __FILE__ ) );
define( 'JQBEB_URL', plugin_dir_url( __FILE__ ) );
define( 'JQBEB_BASENAME', plugin_basename( __FILE__ ) );

require_once JQBEB_DIR . 'includes/class-state-stack.php';
require_once JQBEB_DIR . 'includes/class-plugin.php';

add_action( 'plugins_loaded', [ '\JQBEB\Plugin', 'instance' ] );
