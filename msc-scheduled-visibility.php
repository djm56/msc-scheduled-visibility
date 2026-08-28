<?php
/**
 * Bootstrap file for MSC Scheduled Visibility.
 *
 * Plugin Name: MSC Scheduled Visibility
 * Plugin URI: https://github.com/djm56/msc-scheduled-visibility
 * Description: Schedule posts and pages to appear and disappear between specific dates and times. Includes block-level scheduling, recurring schedules, role-based visibility, WooCommerce product scheduling, quick/bulk edit, schedule preview, history and a REST API.
 * Version: 1.2.4
 * Author: Anomalous Developers
 * Author URI: https://anomalous.co.za
 * Text Domain: msc-scheduled-visibility
 * Domain Path: /languages
 * Requires at least: 5.9
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package MSC_Scheduled_Visibility
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Current plugin version.
 */
define( 'MSCSV_PLUGIN_VERSION', '1.2.4' );

/**
 * Absolute path to the main plugin file.
 */
define( 'MSCSV_PLUGIN_FILE', __FILE__ );

/**
 * Absolute path to the plugin directory.
 */
define( 'MSCSV_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * URL to the plugin directory.
 */
define( 'MSCSV_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once MSCSV_PLUGIN_DIR . 'includes/class-msc-scheduled-visibility-module.php';
require_once MSCSV_PLUGIN_DIR . 'includes/class-msc-scheduled-visibility-settings.php';
require_once MSCSV_PLUGIN_DIR . 'includes/class-msc-scheduled-visibility-quick-edit.php';
require_once MSCSV_PLUGIN_DIR . 'includes/class-msc-scheduled-visibility-woocommerce.php';
require_once MSCSV_PLUGIN_DIR . 'includes/class-msc-scheduled-visibility-preview.php';
require_once MSCSV_PLUGIN_DIR . 'includes/class-msc-scheduled-visibility-audit-log.php';
require_once MSCSV_PLUGIN_DIR . 'includes/class-msc-scheduled-visibility-rest.php';
require_once MSCSV_PLUGIN_DIR . 'includes/class-msc-scheduled-visibility.php';

register_activation_hook(
	__FILE__,
	array( 'MSC_Scheduled_Visibility\\Plugin', 'activate' )
);

register_deactivation_hook(
	__FILE__,
	array( 'MSC_Scheduled_Visibility\\Plugin', 'deactivate' )
);

add_action(
	'plugins_loaded',
	static function () {
		// Translations are loaded automatically by WordPress from the plugin slug;
		// no load_plugin_textdomain() call is needed (core handles this since 4.6).
		MSC_Scheduled_Visibility\Plugin::instance();

		// Self-heal a missing cron event (lost update, cron table reset).
		MSC_Scheduled_Visibility\Plugin::ensure_cron();
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( $links ) {
		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=mscsv-settings' ) ),
			esc_html__( 'Settings', 'msc-scheduled-visibility' )
		);

		array_unshift( $links, $settings );

		return $links;
	}
);
