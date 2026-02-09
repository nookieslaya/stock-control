<?php
/**
 * Plugin Name: Stock Control
 * Plugin URI: https://example.com
 * Description: Provides a secured REST endpoint for setting WooCommerce stock by SKU or product ID.
 * Version: 1.0.0
 * Author: Stock Control
 * Author URI: https://example.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: stock-control
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'STOCK_CONTROL_VERSION', '1.0.0' );
define( 'STOCK_CONTROL_PLUGIN_FILE', __FILE__ );
define( 'STOCK_CONTROL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once STOCK_CONTROL_PLUGIN_DIR . 'src/Support/Logger.php';
require_once STOCK_CONTROL_PLUGIN_DIR . 'src/Service/Stock_Updater.php';
require_once STOCK_CONTROL_PLUGIN_DIR . 'src/Rest/Stock_Controller.php';
require_once STOCK_CONTROL_PLUGIN_DIR . 'src/Plugin.php';

add_action(
	'plugins_loaded',
	static function() {
		\StockControl\Plugin::instance()->register();
	}
);

