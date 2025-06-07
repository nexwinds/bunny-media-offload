<?php
/**
 * Plugin Name: Bunny Media Offload
 * Plugin URI: https://nexwinds.com/bunny-media-offload
 * Description: Seamlessly offload and manage WordPress media with Bunny.net Edge Storage. Compatible with WooCommerce and WPML.
 * Version: 1.0.0
 * Author: NexWinds
 * Author URI: https://nexwinds.com
 * License: GPL v3
 * Text Domain: bunny-media-offload
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('BMO_PLUGIN_FILE', __FILE__);
define('BMO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BMO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BMO_PLUGIN_VERSION', '1.0.0');
define('BMO_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Check for required PHP version
if (version_compare(PHP_VERSION, '7.4', '<')) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>' . esc_html__('Bunny Media Offload requires PHP 7.4 or higher.', 'bunny-media-offload') . '</p></div>';
    });
    return;
}

// Load text domain for translations
add_action('plugins_loaded', 'bmo_load_textdomain');

/**
 * Load plugin text domain for internationalization
 */
function bmo_load_textdomain() {
    load_plugin_textdomain(
        'bunny-media-offload',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages/'
    );
}

// Load the plugin
require_once BMO_PLUGIN_DIR . 'includes/class-bunny-media-offload.php';

// Initialize the plugin
function bmo_init() {
    return Bunny_Media_Offload::get_instance();
}

// Declare WooCommerce HPOS compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Hook initialization - Load text domain first, then initialize
add_action('plugins_loaded', 'bmo_load_textdomain', 1);
add_action('plugins_loaded', 'bmo_init', 10);

// Activation hook
register_activation_hook(__FILE__, array('Bunny_Media_Offload', 'activate'));

// Deactivation hook
register_deactivation_hook(__FILE__, array('Bunny_Media_Offload', 'deactivate'));

// Uninstall hook
register_uninstall_hook(__FILE__, array('Bunny_Media_Offload', 'uninstall')); 