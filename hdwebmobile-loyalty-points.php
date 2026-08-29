<?php

/**
 * Plugin Name: HDWebmobile Loyalty Points & Store Credit
 * Plugin URI: https://hdwebmobile.com/plugins/hdwebmobile-loyalty-points/
 * Description: Customers earn points on completed orders and redeem them for a discount at checkout. Balance is always computed from an append-only ledger, never a value the browser can hand back to the server.
 * Version: 1.0.0
 * Author: htrxuan - Han Tran
 * Author URI: https://hdwebmobile.com/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: hdwebmobile-loyalty-points
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * Requires PHP: 7.4
 * Requires at least: 6.9
 */

namespace htrxuan\hdlp;

if (!defined('ABSPATH')) {
    exit;
}

define('HDLP_VERSION', '1.0.0');
define('HDLP_DB_VERSION', '1.0.0');
define('HDLP_PLUGIN_FILE', __FILE__);
define('HDLP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HDLP_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once HDLP_PLUGIN_DIR . 'includes/class-hdlp-activator.php';

register_activation_hook(__FILE__, array(HDLP_Activator::class, 'activate'));

add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', HDLP_PLUGIN_FILE, true);
    }
});

add_action('plugins_loaded', function () {
    require_once HDLP_PLUGIN_DIR . 'includes/class-hdlp-core.php';
    HDLP_Core::get_instance();
});

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $donate_link = '<a href="https://paypal.me/htrxuan/20" target="_blank" rel="noopener noreferrer">' . esc_html__('Donate', 'hdwebmobile-loyalty-points') . '</a>';
    array_unshift($links, $donate_link);
    return $links;
});
